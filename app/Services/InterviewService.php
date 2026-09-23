<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScope;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Domain\StatusMachine;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\ApplicationRepository;
use App\Repositories\InterviewRepository;
use App\Support\Db;
use App\Support\Ulid;

/**
 * Interview rounds. Each operation changes the interview row and drives the
 * application through ApplicationService::advance() in ONE transaction, so an
 * application can never say "interview scheduled" without an open interview
 * (or the reverse).
 *
 *   schedule    shortlisted / no_show / interview_completed → interview_scheduled
 *   reschedule  interview_scheduled → rescheduled → interview_scheduled (same round)
 *   outcome     selected → interview_completed → selected
 *               rejected → interview_completed → rejected
 *               hold     → interview_completed (waiting for a decision)
 *               no_show  → no_show
 */
final class InterviewService
{
    public function __construct(
        private readonly Db $db,
        private readonly InterviewRepository $interviews,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationService $applicationService,
        private readonly StatusMachine $statuses,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @param array<string,mixed> $data validated by InterviewValidator::schedule() */
    public function schedule(Application $app, array $data, User $actor): Interview
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('interviews.create') || !$g->allows('view', $app)) {
            throw AuthorizationException::forPermission('interviews.create');
        }
        if (!$this->statuses->canTransition('application', $app->status, 'interview_scheduled')) {
            throw new DomainRuleException(
                DomainRuleException::RULE_VIOLATION,
                $app->status === 'applied' || $app->status === 'documents_submitted'
                    ? 'Shortlist the candidate before scheduling an interview.'
                    : 'An interview cannot be scheduled while the application is “' . $app->label() . '”.',
                [],
            );
        }
        if ($this->interviews->hasOpen($app->id)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This application already has an open interview.', []);
        }

        $scope = $this->scopes->resolve($actor);

        $interview = $this->db->transaction(function () use ($app, $data, $actor, $scope): Interview {
            $latest = $this->interviews->latestFor($app->id);
            // A no-show or a reschedule repeats the same round; anything else opens the next one.
            $round = $latest === null ? 1 : (in_array($latest['status'], ['no_show', 'rescheduled'], true) ? $latest['round_no'] : $latest['round_no'] + 1);

            $id = $this->insert($app, $round, $data, $actor);
            $this->applicationService->advance($app->id, 'interview_scheduled', $actor, "Round {$round} interview scheduled for {$data['scheduled_date']}");
            $this->audit->log('scheduled', 'interviews', 'interview', $id, null, [
                'application_id' => $app->id, 'round' => $round, 'date' => $data['scheduled_date'], 'type' => $data['type'],
            ], null, $actor);

            return $this->reload($id, $scope);
        });

        $this->notifyOwner($app, $actor, 'interview_scheduled', "Interview scheduled: {$app->candidateName}", "Round {$interview->roundNo} · {$interview->whenLabel()} · {$interview->typeLabel()} · {$app->jobTitle}");

        return $interview;
    }

    public function confirm(Interview $interview, User $actor): Interview
    {
        if (!$this->gate->forUser($actor)->allows('edit', $interview)) {
            throw AuthorizationException::forPermission('interviews.edit');
        }
        if ($interview->status !== 'scheduled') {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only a scheduled interview can be confirmed.', []);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($interview, $actor, $scope): Interview {
            if ($this->interviews->updateOpen($interview->id, ['status' => 'confirmed']) === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This interview changed just now. Please review it and try again.', []);
            }
            $this->audit->log('confirmed', 'interviews', 'interview', $interview->id, ['status' => 'scheduled'], ['status' => 'confirmed'], null, $actor);

            return $this->reload($interview->id, $scope);
        });
    }

    /**
     * Moves an open interview to a new slot. The old row is kept (status
     * `rescheduled`, reason appended to its notes); a new row continues the round.
     *
     * @param array<string,mixed> $data validated by InterviewValidator::schedule()
     */
    public function reschedule(Interview $interview, array $data, string $reason, User $actor): Interview
    {
        if (!$this->gate->forUser($actor)->allows('edit', $interview)) {
            throw AuthorizationException::forPermission('interviews.edit');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => ['Say why the interview is being rescheduled.']]);
        }
        if (!$interview->isOpen()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only an open interview can be rescheduled.', []);
        }

        $scope = $this->scopes->resolve($actor);

        $new = $this->db->transaction(function () use ($interview, $data, $reason, $actor, $scope): Interview {
            $app = $this->applicationFor($interview, $scope);
            $stamp = 'Rescheduled: ' . mb_substr($reason, 0, 200);
            $notes = trim(($interview->notes !== null ? $interview->notes . "\n" : '') . $stamp);

            if ($this->interviews->updateOpen($interview->id, ['status' => 'rescheduled', 'notes' => mb_substr($notes, 0, 2000)]) === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This interview changed just now. Please review it and try again.', []);
            }

            $this->applicationService->advance($app->id, 'rescheduled', $actor, "Round {$interview->roundNo} rescheduled: {$reason}");
            $id = $this->insert($app, $interview->roundNo, $data, $actor);
            $this->applicationService->advance($app->id, 'interview_scheduled', $actor, "Round {$interview->roundNo} rescheduled to {$data['scheduled_date']}");
            $this->audit->log('rescheduled', 'interviews', 'interview', $id, [
                'interview_id' => $interview->id, 'date' => $interview->scheduledDate,
            ], ['date' => $data['scheduled_date'], 'type' => $data['type']], $reason, $actor);

            return $this->reload($id, $scope);
        });

        $this->notifyOwner($this->applicationFor($new, $scope), $actor, 'interview_rescheduled', "Interview rescheduled: {$new->candidateName}", "Round {$new->roundNo} now {$new->whenLabel()} · {$new->typeLabel()}");

        return $new;
    }

    /**
     * Records what happened. `selected` / `rejected` also move the application
     * on; `hold` parks it at interview_completed; `no_show` keeps it re-schedulable.
     */
    public function recordOutcome(Interview $interview, string $outcome, ?string $feedback, User $actor): Interview
    {
        if (!$this->gate->forUser($actor)->allows('recordOutcome', $interview)) {
            throw AuthorizationException::forPermission('interviews.record_outcome');
        }
        if (!in_array($outcome, ['selected', 'rejected', 'hold', 'no_show'], true)) {
            throw new ValidationException(['outcome' => ['Choose an outcome.']]);
        }
        $feedback = $feedback !== null && trim($feedback) !== '' ? trim($feedback) : null;
        if ($outcome === 'rejected' && $feedback === null) {
            throw new ValidationException(['feedback' => ['Give feedback when rejecting a candidate.']]);
        }
        if (!$interview->isOpen()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'An outcome has already been recorded for this interview.', []);
        }
        if ($interview->scheduledDate > gmdate('Y-m-d')) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This interview is scheduled for ' . $interview->scheduledDate . ' — record the outcome once it has taken place.', []);
        }

        $scope = $this->scopes->resolve($actor);
        $round = $interview->roundNo;

        $updated = $this->db->transaction(function () use ($interview, $outcome, $feedback, $actor, $scope, $round): Interview {
            $app = $this->applicationFor($interview, $scope);
            [$status, $result] = match ($outcome) {
                'selected' => ['selected', 'selected'],
                'rejected' => ['rejected', 'rejected'],
                'hold'     => ['completed', 'hold'],
                default    => ['no_show', 'pending'],
            };

            if ($this->interviews->updateOpen($interview->id, ['status' => $status, 'result' => $result, 'feedback' => $feedback !== null ? mb_substr($feedback, 0, 4000) : null]) === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This interview changed just now. Please review it and try again.', []);
            }

            $note = $feedback !== null ? ': ' . mb_substr($feedback, 0, 200) : '';
            match ($outcome) {
                'selected' => $this->advanceTwice($app->id, 'selected', "Round {$round} selected{$note}", $actor),
                'rejected' => $this->advanceTwice($app->id, 'rejected', "Round {$round} rejected{$note}", $actor),
                'hold'     => $this->applicationService->advance($app->id, 'interview_completed', $actor, "Round {$round} on hold{$note}"),
                default    => $this->applicationService->advance($app->id, 'no_show', $actor, "Round {$round} — candidate did not attend"),
            };

            $this->audit->log('outcome_recorded', 'interviews', 'interview', $interview->id, ['status' => $interview->status], ['status' => $status, 'result' => $result], $feedback, $actor);

            return $this->reload($interview->id, $scope);
        });

        $this->notifyOwner($this->applicationFor($updated, $scope), $actor, 'interview_outcome', "Interview result: {$updated->candidateName} — " . strtolower($updated->statusLabel()), "Round {$round} · {$updated->jobTitle}");

        return $updated;
    }

    // ---- internals -------------------------------------------------

    /** interview_scheduled → interview_completed → $final. */
    private function advanceTwice(int $applicationId, string $final, string $reason, User $actor): void
    {
        $this->applicationService->advance($applicationId, 'interview_completed', $actor, $reason);
        $this->applicationService->advance($applicationId, $final, $actor, $reason);
    }

    /** @param array<string,mixed> $data */
    private function insert(Application $app, int $round, array $data, User $actor): int
    {
        return $this->interviews->create([
            'public_id'      => Ulid::generate(),
            'application_id' => $app->id,
            'candidate_id'   => $app->candidateId,
            'job_id'         => $app->jobId,
            'employer_id'    => $app->employerId,
            'round_no'       => $round,
            'type'           => $data['type'],
            'scheduled_date' => $data['scheduled_date'],
            'scheduled_time' => $data['scheduled_time'] ?? null,
            'location'       => $data['location'] ?? null,
            'meeting_link'   => $data['meeting_link'] ?? null,
            'interviewer'    => $data['interviewer'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'status'         => 'scheduled',
            'result'         => 'pending',
            'created_by'     => $actor->id,
        ]);
    }

    private function applicationFor(Interview $interview, BranchScope $scope): Application
    {
        $app = $this->applications->findById($interview->applicationId, $scope);
        if ($app === null) {
            throw new \RuntimeException('Application vanished mid-operation.');
        }

        return $app;
    }

    private function reload(int $id, BranchScope $scope): Interview
    {
        $interview = $this->interviews->findById($id, $scope);
        if ($interview === null) {
            throw new \RuntimeException('Interview vanished mid-operation.');
        }

        return $interview;
    }

    /** Tell the application's owner about a change someone else made. */
    private function notifyOwner(Application $app, User $actor, string $type, string $title, string $body): void
    {
        if ($app->assignedTo === null || $app->assignedTo === $actor->id) {
            return;
        }

        $this->notifications->notify(
            userId: $app->assignedTo,
            type: $type,
            title: $title,
            body: $body,
            linkType: 'application',
            linkId: $app->id,
            linkFragment: 'interviews',
        );
    }
}
