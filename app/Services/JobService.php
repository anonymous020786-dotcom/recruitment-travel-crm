<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Domain\StatusMachine;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Employer;
use App\Models\Job;
use App\Models\JobRequirement;
use App\Models\User;
use App\Repositories\JobBenefitRepository;
use App\Repositories\JobRepository;
use App\Repositories\JobRequirementRepository;
use App\Repositories\SkillRepository;
use App\Support\Db;
use App\Support\Sequences;
use App\Support\Slug;
use App\Support\Ulid;

/**
 * Job posting workflows. The lifecycle is enforced by the `job` StatusMachine
 * (no override exists); "public" is only ever true while a job is `open` and is
 * cleared automatically on any move away from it or on delete.
 */
final class JobService
{
    private const TERMINAL = ['closed', 'cancelled'];
    private const DELETABLE = ['draft', 'closed', 'cancelled'];

    public function __construct(
        private readonly Db $db,
        private readonly JobRepository $jobs,
        private readonly JobRequirementRepository $requirements,
        private readonly JobBenefitRepository $benefits,
        private readonly SkillRepository $skills,
        private readonly StatusMachine $statuses,
        private readonly Sequences $sequences,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
    ) {
    }

    /** @param array<string,mixed> $data validated JobValidator output */
    public function create(Employer $employer, array $data, User $actor): Job
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('jobs.create') || !$g->allows('view', $employer)) {
            throw AuthorizationException::forPermission('jobs.create');
        }
        if (in_array($employer->status, ['suspended', 'blacklisted', 'inactive'], true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, "Cannot post jobs for a {$employer->status} employer.", []);
        }

        $scope = $this->scopes->resolve($actor);
        $branchId = $employer->branchId ?? $actor->primaryBranchId;
        if ($branchId === null || !$scope->contains($branchId)) {
            throw new ValidationException(['employer' => ['That employer is not in a branch you can post jobs for.']]);
        }

        return $this->db->transaction(function () use ($employer, $data, $actor, $branchId, $scope): Job {
            $number = $this->sequences->next('job', 'JOB', 6);
            $id = $this->jobs->create($data + [
                'public_id'   => Ulid::generate(),
                'job_number'  => $number,
                'slug'        => Slug::make((string) $data['title'], 140) . '-' . strtolower($number),
                'employer_id' => $employer->id,
                'branch_id'   => $branchId,
                'status'      => 'draft',
                'is_public'   => 0,
                'created_by'  => $actor->id,
            ]);
            $this->audit->log('created', 'jobs', 'job', $id, null, ['title' => $data['title'], 'employer_id' => $employer->id], null, $actor);

            return $this->reload($id, $scope);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(Job $job, array $data, User $actor): Job
    {
        $this->authorize('update', $job, $actor, 'jobs.edit');
        $this->assertEditable($job);

        $scope = $this->scopes->resolve($actor);
        $before = $this->snapshot($job);

        return $this->db->transaction(function () use ($job, $data, $scope, $actor, $before): Job {
            $this->jobs->update($job->id, $data, $scope);
            $fresh = $this->reload($job->id, $scope);
            $this->audit->log('updated', 'jobs', 'job', $job->id, $before, $this->snapshot($fresh), null, $actor);

            return $fresh;
        });
    }

    public function changeStatus(Job $job, string $to, User $actor, ?string $reason = null): Job
    {
        $this->authorize('changeStatus', $job, $actor, 'jobs.change_status');
        $this->statuses->assert('job', $job->status, $to);

        if ($to === 'open' && $job->isDeadlinePassed()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The application deadline has passed — extend it before opening this job.', []);
        }
        if (in_array($to, ['cancelled', 'closed'], true) && ($reason === null || trim($reason) === '')) {
            throw new ValidationException(['reason' => ['Please give a reason.']]);
        }

        $scope = $this->scopes->resolve($actor);
        $extra = $to === 'open' ? [] : ['is_public' => 0];

        return $this->db->transaction(function () use ($job, $to, $reason, $extra, $scope, $actor): Job {
            if ($this->jobs->transition($job->id, $job->status, $to, $extra, $scope) === 0) {
                throw new StaleRecordException('job', $job->publicId);
            }
            $this->audit->log('status_changed', 'jobs', 'job', $job->id, ['status' => $job->status], ['status' => $to], $reason !== null ? trim($reason) : null, $actor);

            return $this->reload($job->id, $scope);
        });
    }

    public function setPublic(Job $job, bool $public, User $actor): Job
    {
        $this->authorize('publish', $job, $actor, 'jobs.publish');
        if ($public && $job->status !== 'open') {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only an open job can be published to the public site.', []);
        }

        $scope = $this->scopes->resolve($actor);
        $this->jobs->update($job->id, ['is_public' => $public ? 1 : 0], $scope);
        $this->audit->log($public ? 'published' : 'unpublished', 'jobs', 'job', $job->id, null, null, null, $actor);

        return $this->reload($job->id, $scope);
    }

    public function delete(Job $job, User $actor): void
    {
        $this->authorize('delete', $job, $actor, 'jobs.delete');
        if (!in_array($job->status, self::DELETABLE, true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Close or cancel this job before deleting it.', []);
        }

        $scope = $this->scopes->resolve($actor);
        if ($this->jobs->softDelete($job->id, $scope) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Job not found.', [], 404);
        }
        $this->audit->log('deleted', 'jobs', 'job', $job->id, $this->snapshot($job), null, null, $actor);
    }

    /** @param array{label:string,is_mandatory:bool,weight:int} $data */
    public function addRequirement(Job $job, array $data, User $actor): JobRequirement
    {
        $this->authorize('update', $job, $actor, 'jobs.edit');
        $this->assertEditable($job);

        $id = $this->requirements->create([
            'job_id'       => $job->id,
            'skill_id'     => $this->skills->findIdByName($data['label']),
            'label'        => $data['label'],
            'is_mandatory' => $data['is_mandatory'] ? 1 : 0,
            'weight'       => $data['weight'],
        ]);
        $this->audit->log('requirement_added', 'jobs', 'job', $job->id, null, ['requirement_id' => $id] + $data, null, $actor);

        foreach ($this->requirements->forJob($job->id) as $r) {
            if ($r->id === $id) {
                return $r;
            }
        }
        throw new \RuntimeException('Requirement vanished immediately after insert.');
    }

    public function removeRequirement(Job $job, int $requirementId, User $actor): void
    {
        $this->authorize('update', $job, $actor, 'jobs.edit');
        $this->assertEditable($job);

        if ($this->requirements->delete($requirementId, $job->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Requirement not found.', [], 404);
        }
        $this->audit->log('requirement_removed', 'jobs', 'job', $job->id, ['requirement_id' => $requirementId], null, null, $actor);
    }

    public function addBenefit(Job $job, string $label, User $actor): void
    {
        $this->authorize('update', $job, $actor, 'jobs.edit');
        $this->assertEditable($job);

        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            throw new ValidationException(['label' => ['Enter a benefit of up to 120 characters.']]);
        }
        $id = $this->benefits->create($job->id, $label);
        $this->audit->log('benefit_added', 'jobs', 'job', $job->id, null, ['benefit_id' => $id, 'label' => $label], null, $actor);
    }

    public function removeBenefit(Job $job, int $benefitId, User $actor): void
    {
        $this->authorize('update', $job, $actor, 'jobs.edit');
        $this->assertEditable($job);

        if ($this->benefits->delete($benefitId, $job->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Benefit not found.', [], 404);
        }
        $this->audit->log('benefit_removed', 'jobs', 'job', $job->id, ['benefit_id' => $benefitId], null, null, $actor);
    }

    // ---- internals -------------------------------------------------

    private function authorize(string $ability, Job $job, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $job)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function assertEditable(Job $job): void
    {
        if (in_array($job->status, self::TERMINAL, true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, "A {$job->status} job can no longer be edited.", []);
        }
    }

    private function reload(int $id, \App\Auth\BranchScope $scope): Job
    {
        $job = $this->jobs->findById($id, $scope);
        if ($job === null) {
            throw new \RuntimeException('Job vanished mid-operation.');
        }

        return $job;
    }

    /** @return array<string,mixed> */
    private function snapshot(Job $j): array
    {
        return [
            'title' => $j->title, 'country' => $j->country, 'vacancies' => $j->vacancies, 'status' => $j->status,
            'salary_min' => $j->salaryMin, 'salary_max' => $j->salaryMax, 'currency' => $j->currency,
            'deadline' => $j->deadline, 'is_public' => $j->isPublic,
        ];
    }
}
