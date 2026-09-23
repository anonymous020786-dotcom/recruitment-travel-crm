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
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\User;
use App\Repositories\ApplicationHistoryRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\CandidateRepository;
use App\Support\Db;
use App\Support\Sequences;
use App\Support\Ulid;

/**
 * Application workflows. Every status change — manual, override, or driven by
 * an interview outcome — goes through one core (`transition()`): assert against
 * the StatusMachine, optimistic-locked write, an append-only history row, the
 * candidate stage snapshot, and an audit entry, all in one transaction.
 */
final class ApplicationService
{
    /** States that need a reason no matter how they are reached. */
    private const REASON_REQUIRED = ['rejected', 'cancelled'];

    public function __construct(
        private readonly Db $db,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationHistoryRepository $history,
        private readonly CandidateRepository $candidates,
        private readonly StatusMachine $statuses,
        private readonly MatchService $matches,
        private readonly Sequences $sequences,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
    ) {
    }

    public function create(Candidate $candidate, Job $job, User $actor): Application
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('applications.create') || !$g->allows('view', $candidate) || !$g->allows('view', $job)) {
            throw AuthorizationException::forPermission('applications.create');
        }
        if (!$candidate->isActive) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This candidate is inactive.', []);
        }
        if ($job->status !== 'open' || $job->isDeadlinePassed()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Applications can only be made to an open job that is still accepting them.', []);
        }
        if ($this->applications->existsFor($candidate->id, $job->id)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This candidate has already applied to this job.', []);
        }

        $scope = $this->scopes->resolve($actor);
        $match = $this->matches->scorePair($candidate, $job, $scope);

        return $this->db->transaction(function () use ($candidate, $job, $actor, $scope, $match): Application {
            $id = $this->applications->create([
                'public_id'          => Ulid::generate(),
                'application_number' => $this->sequences->next('application', 'APP', 6),
                'candidate_id'       => $candidate->id,
                'job_id'             => $job->id,
                'employer_id'        => $job->employerId,
                'branch_id'          => $candidate->branchId,
                'status'             => 'applied',
                'match_score'        => $match?->score,
                'match_breakdown'    => $match !== null ? json_encode($match->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'assigned_to'        => $candidate->assignedCounselor,
                'created_by'         => $actor->id,
            ]);
            $this->history->append($id, null, 'applied', false, null, $actor->id);
            $this->refreshStage($candidate->id);
            $this->audit->log('created', 'applications', 'application', $id, null, [
                'candidate_id' => $candidate->id, 'job_id' => $job->id, 'match_score' => $match?->score,
            ], null, $actor);

            return $this->reload($id, $scope);
        });
    }

    /**
     * Manual status change. $override lets an actor holding
     * applications.override_status make a move the transition table forbids
     * (including reopening a rejected application); it is recorded as an
     * override and needs a reason.
     */
    public function changeStatus(Application $app, string $to, User $actor, int $expectedVersion, ?string $reason = null, bool $override = false): Application
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('changeStatus', $app)) {
            throw AuthorizationException::forPermission('applications.change_status');
        }
        if ($override && !$g->allows('overrideStatus', $app)) {
            throw AuthorizationException::forPermission('applications.override_status');
        }

        return $this->db->transaction(fn (): Application => $this->transition($app, $to, $actor, $expectedVersion, $reason, $override));
    }

    /**
     * System-driven move (interview outcomes). Not permission-gated by
     * applications.* — the caller has already authorized the triggering action —
     * but the transition table is still enforced and the move is fully audited.
     * Must be called inside the caller's transaction.
     */
    public function advance(int $applicationId, string $to, User $actor, string $reason): Application
    {
        $scope = $this->scopes->resolve($actor);
        $app = $this->reload($applicationId, $scope);

        return $this->transition($app, $to, $actor, $app->recordVersion, $reason, false);
    }

    // ---- internals -------------------------------------------------

    private function transition(Application $app, string $to, User $actor, int $expectedVersion, ?string $reason, bool $allowOverride): Application
    {
        $isOverride = $this->statuses->assert('application', $app->status, $to, $allowOverride);
        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        if (($isOverride || in_array($to, self::REASON_REQUIRED, true)) && $reason === null) {
            throw new ValidationException(['reason' => [$isOverride ? 'An override needs a reason.' : 'Please give a reason.']]);
        }

        $terminalTo = in_array($to, Application::TERMINAL, true);
        $extra = [];
        if ($terminalTo) {
            $extra['closed_at'] = gmdate('Y-m-d H:i:s');
            $extra['cancel_reason'] = $to === 'cancelled' ? mb_substr((string) $reason, 0, 255) : null;
        } elseif ($app->isTerminal()) { // reopened by an override
            $extra['closed_at'] = null;
            $extra['cancel_reason'] = null;
        }

        $scope = $this->scopes->resolve($actor);
        if ($this->applications->updateStatus($app->id, $to, $extra, $expectedVersion, $scope) === 0) {
            throw new StaleRecordException('application', $app->publicId);
        }
        $this->history->append($app->id, $app->status, $to, $isOverride, $reason !== null ? mb_substr($reason, 0, 255) : null, $actor->id);
        $this->refreshStage($app->candidateId);
        $this->audit->log(
            $isOverride ? 'status_overridden' : 'status_changed',
            'applications',
            'application',
            $app->id,
            ['status' => $app->status],
            ['status' => $to],
            $reason,
            $actor,
        );

        return $this->reload($app->id, $scope);
    }

    /** candidates.stage = the most advanced live (not rejected/cancelled) application status, else 'registered'. */
    private function refreshStage(int $candidateId): void
    {
        $order = array_flip($this->statuses->states('application'));
        $best = null;
        foreach ($this->applications->statusesForCandidate($candidateId) as $status) {
            if (in_array($status, ['rejected', 'cancelled'], true) || !isset($order[$status])) {
                continue;
            }
            if ($best === null || $order[$status] > $order[$best]) {
                $best = $status;
            }
        }

        $this->candidates->setStage($candidateId, $best ?? 'registered');
    }

    private function reload(int $id, \App\Auth\BranchScope $scope): Application
    {
        $app = $this->applications->findById($id, $scope);
        if ($app === null) {
            throw new \RuntimeException('Application vanished mid-operation.');
        }

        return $app;
    }
}
