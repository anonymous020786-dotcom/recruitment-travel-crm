<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Domain\Matching\MatchEngine;
use App\Domain\Matching\MatchResult;
use App\Exceptions\AuthorizationException;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\User;
use App\Repositories\JobRepository;
use App\Repositories\JobRequirementRepository;
use App\Repositories\MatchProfileRepository;

/**
 * Runs the match engine over pools the actor may see. Nothing is persisted —
 * scores are computed on demand and shown with their breakdown (Phase 6 stores
 * the breakdown on an application when one is created).
 */
final class MatchService
{
    public function __construct(
        private readonly MatchEngine $engine,
        private readonly MatchProfileRepository $profiles,
        private readonly JobRepository $jobs,
        private readonly JobRequirementRepository $requirements,
        private readonly Gate $gate,
        private readonly BranchScopeResolver $scopes,
        private readonly array $config = [],
    ) {
    }

    /**
     * Candidates ranked for one job: eligible first, then by score.
     *
     * @return list<array{candidate:array<string,mixed>,result:MatchResult}>
     */
    public function rankCandidatesForJob(Job $job, User $actor, ?int $limit = null): array
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('match', $job) || !$g->allows('candidates.view')) {
            throw AuthorizationException::forPermission('jobs.match');
        }

        $jobProfile = $this->jobProfile($job, $this->requirements->forJob($job->id));
        $pool = $this->profiles->candidateProfiles($this->scopes->resolve($actor), null, (int) ($this->config['pool_limit'] ?? 300));

        $rows = [];
        foreach ($pool as $c) {
            $rows[] = ['candidate' => $c['meta'], 'result' => $this->engine->score($c['profile'], $jobProfile)];
        }

        return $this->rank($rows, $limit);
    }

    /**
     * Open jobs ranked for one candidate.
     *
     * @return list<array{job:Job,result:MatchResult}>
     */
    public function rankJobsForCandidate(Candidate $candidate, User $actor, ?int $limit = null): array
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('jobs.match') || !$g->allows('view', $candidate) || !$g->allows('jobs.view')) {
            throw AuthorizationException::forPermission('jobs.match');
        }

        $scope = $this->scopes->resolve($actor);
        $profile = $this->profiles->candidateProfiles($scope, [$candidate->id], 1)[0]['profile'] ?? null;
        if ($profile === null) {
            return [];
        }

        $jobs = $this->jobs->openJobs($scope, (int) ($this->config['pool_limit'] ?? 300));
        $reqs = $this->requirements->forJobs(array_map(static fn (Job $j): int => $j->id, $jobs));

        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = ['job' => $job, 'result' => $this->engine->score($profile, $this->jobProfile($job, $reqs[$job->id] ?? []))];
        }

        return $this->rank($rows, $limit);
    }

    /**
     * Score one candidate against one job without any permission check — for
     * internal callers (ApplicationService snapshots it) that authorize
     * themselves. Returns null if the candidate is outside the given scope.
     */
    public function scorePair(Candidate $candidate, Job $job, \App\Auth\BranchScope $scope): ?MatchResult
    {
        $profile = $this->profiles->candidateProfiles($scope, [$candidate->id], 1)[0]['profile'] ?? null;
        if ($profile === null) {
            return null;
        }

        return $this->engine->score($profile, $this->jobProfile($job, $this->requirements->forJob($job->id)));
    }

    /** @param list<\App\Models\JobRequirement> $requirements @return array<string,mixed> */
    private function jobProfile(Job $job, array $requirements): array
    {
        return [
            'country' => $job->country, 'experience_required' => $job->experienceRequired, 'qualification' => $job->qualification,
            'age_min' => $job->ageMin, 'age_max' => $job->ageMax, 'gender_requirement' => $job->genderRequirement,
            'salary_min' => $job->salaryMin, 'salary_max' => $job->salaryMax, 'currency' => $job->currency,
            'requirements' => array_map(static fn ($r): array => [
                'label' => $r->label, 'skill_id' => $r->skillId, 'is_mandatory' => $r->isMandatory, 'weight' => $r->weight,
            ], $requirements),
        ];
    }

    /**
     * @param list<array{result:MatchResult}&array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function rank(array $rows, ?int $limit): array
    {
        $min = (float) ($this->config['min_score_shown'] ?? 0);
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['result']->score >= $min));

        usort($rows, static function (array $a, array $b): int {
            return [(int) $b['result']->eligible, $b['result']->score] <=> [(int) $a['result']->eligible, $a['result']->score];
        });

        return array_slice($rows, 0, $limit ?? (int) ($this->config['result_limit'] ?? 25));
    }
}
