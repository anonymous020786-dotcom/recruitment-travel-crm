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
use App\Models\Application;
use App\Models\Candidate;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\ApplicationRepository;
use App\Repositories\MedicalRepository;
use App\Support\Db;
use App\Support\Ulid;

/**
 * Medical examinations. pending → scheduled → completed → fit | unfit | retest
 * (config/statuses.php `medical`). A *fit* verdict on an application that is
 * `medical_pending` moves it to `medical_completed` in the same transaction;
 * unfit / retest only notify the application's owner — deciding what happens
 * to the candidate stays a human call.
 */
final class MedicalService
{
    /** A fit certificate is assumed valid this long when the clinic gives no expiry date. */
    public const DEFAULT_VALIDITY_DAYS = 90;

    public function __construct(
        private readonly Db $db,
        private readonly MedicalRepository $records,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationService $applicationService,
        private readonly StatusMachine $statuses,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @param array{medical_center:?string,appointment_date:?string,notes:?string} $data from MedicalValidator::book() */
    public function book(Candidate $candidate, ?Application $application, array $data, User $actor): MedicalRecord
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('medical.create') || !$g->allows('view', $candidate)) {
            throw AuthorizationException::forPermission('medical.create');
        }
        if ($application !== null) {
            if ($application->candidateId !== $candidate->id) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That application belongs to a different candidate.', []);
            }
            if ($application->isTerminal()) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That application is closed.', []);
            }
        }
        if ($this->records->hasOpenFor($candidate->id, $application?->id)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A medical is already in progress for this candidate' . ($application !== null ? ' and application' : '') . '.', []);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($candidate, $application, $data, $actor, $scope): MedicalRecord {
            $id = $this->records->create([
                'public_id'        => Ulid::generate(),
                'candidate_id'     => $candidate->id,
                'application_id'   => $application?->id,
                'medical_center'   => $data['medical_center'],
                'appointment_date' => $data['appointment_date'],
                'notes'            => $data['notes'],
                'status'           => $data['appointment_date'] !== null ? 'scheduled' : 'pending',
                'result'           => 'pending',
                'created_by'       => $actor->id,
            ]);
            $this->audit->log('created', 'medical', 'medical_record', $id, null, [
                'candidate_id' => $candidate->id, 'application_id' => $application?->id, 'appointment_date' => $data['appointment_date'],
            ], null, $actor);

            return $this->reload($id, $scope);
        });
    }

    /** Change the clinic / appointment of an exam that has not happened yet. @param array{medical_center:?string,appointment_date:?string,notes:?string} $data */
    public function reschedule(MedicalRecord $record, array $data, User $actor): MedicalRecord
    {
        $this->requireEdit($record, $actor);
        if (!in_array($record->status, ['pending', 'scheduled'], true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The appointment can only be changed before the medical takes place.', []);
        }

        $to = $data['appointment_date'] !== null ? 'scheduled' : 'pending';
        if ($to === 'pending' && $record->status === 'scheduled') {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Keep an appointment date on a scheduled medical.', []);
        }
        if ($to !== $record->status) {
            $this->statuses->assert('medical', $record->status, $to);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($record, $data, $to, $actor, $scope): MedicalRecord {
            $this->guarded($record, [
                'medical_center' => $data['medical_center'], 'appointment_date' => $data['appointment_date'],
                'notes' => $data['notes'] ?? $record->notes, 'status' => $to,
            ]);
            $this->audit->log('rescheduled', 'medical', 'medical_record', $record->id,
                ['appointment_date' => $record->appointmentDate, 'center' => $record->medicalCenter],
                ['appointment_date' => $data['appointment_date'], 'center' => $data['medical_center']], null, $actor);

            return $this->reload($record->id, $scope);
        });
    }

    /** The candidate attended; the report is still to come. */
    public function markAttended(MedicalRecord $record, string $medicalDate, User $actor): MedicalRecord
    {
        $this->requireEdit($record, $actor);
        $this->statuses->assert('medical', $record->status, 'completed');

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($record, $medicalDate, $actor, $scope): MedicalRecord {
            $this->guarded($record, ['status' => 'completed', 'medical_date' => $medicalDate]);
            $this->audit->log('attended', 'medical', 'medical_record', $record->id, ['status' => $record->status], ['status' => 'completed', 'medical_date' => $medicalDate], null, $actor);

            return $this->reload($record->id, $scope);
        });
    }

    /** @param array{result:string,report_date:string,expires_at:?string,notes:?string} $data from MedicalValidator::result() */
    public function recordResult(MedicalRecord $record, array $data, User $actor): MedicalRecord
    {
        $this->requireEdit($record, $actor);
        $result = $data['result'];
        $this->statuses->assert('medical', $record->status, $result);

        $expires = $result === 'fit'
            ? ($data['expires_at'] ?? (new \DateTimeImmutable($data['report_date'], new \DateTimeZone('UTC')))->modify('+' . self::DEFAULT_VALIDITY_DAYS . ' days')->format('Y-m-d'))
            : null;
        $scope = $this->scopes->resolve($actor);

        $updated = $this->db->transaction(function () use ($record, $data, $result, $expires, $actor, $scope): MedicalRecord {
            $this->guarded($record, [
                'status' => $result, 'result' => $result, 'report_date' => $data['report_date'], 'expires_at' => $expires,
                'medical_date' => $record->medicalDate ?? $data['report_date'],
                'notes' => $data['notes'] ?? $record->notes,
            ]);

            if ($result === 'fit' && $record->applicationId !== null) {
                $app = $this->applications->findById($record->applicationId, $scope);
                if ($app !== null && $app->status === 'medical_pending') {
                    $this->applicationService->advance($app->id, 'medical_completed', $actor, 'Medical: fit');
                }
            }
            $this->audit->log('result_recorded', 'medical', 'medical_record', $record->id, ['status' => $record->status], ['status' => $result, 'expires_at' => $expires], $data['notes'], $actor);

            return $this->reload($record->id, $scope);
        });

        if ($result !== 'fit' && $updated->applicationId !== null) {
            $app = $this->applications->findById($updated->applicationId, $scope);
            if ($app !== null && $app->assignedTo !== null && $app->assignedTo !== $actor->id) {
                $this->notifications->notify(
                    userId: $app->assignedTo,
                    type: 'medical_' . $result,
                    title: 'Medical ' . ($result === 'unfit' ? 'unfit' : 'needs a retest') . ": {$updated->candidateName}",
                    body: "{$app->applicationNumber} · {$app->jobTitle}",
                    linkType: 'application',
                    linkId: $app->id,
                    linkFragment: null,
                );
            }
        }

        return $updated;
    }

    public function delete(MedicalRecord $record, User $actor): void
    {
        if (!$this->gate->forUser($actor)->allows('delete', $record)) {
            throw AuthorizationException::forPermission('medical.delete');
        }
        if ($this->records->deleteUnstarted($record->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only a medical that has not taken place can be deleted.', []);
        }
        $this->audit->log('deleted', 'medical', 'medical_record', $record->id, ['status' => $record->status], null, null, $actor);
    }

    // ---- internals -------------------------------------------------

    private function requireEdit(MedicalRecord $record, User $actor): void
    {
        if (!$this->gate->forUser($actor)->allows('edit', $record)) {
            throw AuthorizationException::forPermission('medical.edit');
        }
    }

    /** @param array<string,mixed> $set */
    private function guarded(MedicalRecord $record, array $set): void
    {
        if ($this->records->updateOpen($record->id, $set) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This medical changed just now. Please review it and try again.', []);
        }
    }

    private function reload(int $id, BranchScope $scope): MedicalRecord
    {
        $record = $this->records->findById($id, $scope);
        if ($record === null) {
            throw new \RuntimeException('Medical record vanished mid-operation.');
        }

        return $record;
    }
}
