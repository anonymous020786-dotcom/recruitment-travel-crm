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
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Models\VisaApplication;
use App\Notifications\NotificationService;
use App\Repositories\ApplicationRepository;
use App\Repositories\VisaHistoryRepository;
use App\Repositories\VisaRepository;
use App\Support\Db;
use App\Support\Ulid;

/**
 * Visa applications. not_started → documents_pending → submitted →
 * under_processing → approved (config/statuses.php `visa`), every move in an
 * append-only history.
 *
 * Application link: starting a visa for an application that has completed its
 * medical moves it to `visa_processing`; approving the visa moves it to
 * `visa_approved`. A rejection only notifies the owner — whether to re-apply or
 * reject the candidate is a human call.
 */
final class VisaService
{
    /** Statuses in which an application may have a visa started for it. */
    private const APPLICATION_READY = ['medical_completed', 'visa_processing'];

    public function __construct(
        private readonly Db $db,
        private readonly VisaRepository $visas,
        private readonly VisaHistoryRepository $history,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationService $applicationService,
        private readonly StatusMachine $statuses,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @param array{country:string,visa_type:?string,visa_number:?string,reference_number:?string,sponsor:?string,notes:?string} $data from VisaValidator::details() */
    public function create(Candidate $candidate, ?Application $application, array $data, User $actor): VisaApplication
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('visa.create') || !$g->allows('view', $candidate)) {
            throw AuthorizationException::forPermission('visa.create');
        }
        $this->requireCountry($data['country']);

        if ($application !== null) {
            if ($application->candidateId !== $candidate->id) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That application belongs to a different candidate.', []);
            }
            if (!in_array($application->status, self::APPLICATION_READY, true)) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The medical must be completed before the visa is started (application is “' . $application->label() . '”).', []);
            }
            $data['sponsor'] ??= $application->employerName;
        }
        if ($this->visas->hasLiveFor($candidate->id, $application?->id)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A visa application is already in progress for this candidate' . ($application !== null ? ' and application' : '') . '.', []);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($candidate, $application, $data, $actor, $scope): VisaApplication {
            $id = $this->visas->create([
                'public_id'        => Ulid::generate(),
                'candidate_id'     => $candidate->id,
                'application_id'   => $application?->id,
                'country'          => $data['country'],
                'visa_type'        => $data['visa_type'],
                'visa_number'      => $data['visa_number'],
                'reference_number' => $data['reference_number'],
                'sponsor'          => $data['sponsor'],
                'notes'            => $data['notes'],
                'status'           => 'not_started',
                'created_by'       => $actor->id,
            ]);
            $this->history->append($id, null, 'not_started', false, null, $actor->id);

            if ($application !== null && $application->status === 'medical_completed') {
                $this->applicationService->advance($application->id, 'visa_processing', $actor, 'Visa application started');
            }
            $this->audit->log('created', 'visa', 'visa_application', $id, null, [
                'candidate_id' => $candidate->id, 'application_id' => $application?->id, 'country' => $data['country'],
            ], null, $actor);

            return $this->reload($id, $scope);
        });
    }

    /** @param array{country:string,visa_type:?string,visa_number:?string,reference_number:?string,sponsor:?string,notes:?string} $data */
    public function update(VisaApplication $visa, array $data, User $actor, int $expectedVersion): VisaApplication
    {
        if (!$this->gate->forUser($actor)->allows('edit', $visa)) {
            throw AuthorizationException::forPermission('visa.edit');
        }
        if ($visa->isClosed()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A ' . strtolower($visa->label()) . ' visa application can no longer be edited.', []);
        }
        $this->requireCountry($data['country']);

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($visa, $data, $actor, $expectedVersion, $scope): VisaApplication {
            if ($this->visas->update($visa->id, $data, $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('visa', $visa->publicId);
            }
            $this->audit->log('updated', 'visa', 'visa_application', $visa->id, [
                'country' => $visa->country, 'visa_type' => $visa->visaType, 'visa_number' => $visa->visaNumber, 'sponsor' => $visa->sponsor,
            ], [
                'country' => $data['country'], 'visa_type' => $data['visa_type'], 'visa_number' => $data['visa_number'], 'sponsor' => $data['sponsor'],
            ], null, $actor);

            return $this->reload($visa->id, $scope);
        });
    }

    /**
     * @param array{status:string,reason:?string,visa_number:?string,submission_date:?string,approval_date:?string,expiry_date:?string} $data from VisaValidator::status()
     * @param bool $override let an actor holding visa.override_status make a move the table forbids (audited, reason mandatory)
     */
    public function changeStatus(VisaApplication $visa, array $data, User $actor, int $expectedVersion, bool $override = false): VisaApplication
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('changeStatus', $visa)) {
            throw AuthorizationException::forPermission('visa.change_status');
        }
        if ($override && !$g->allows('overrideStatus', $visa)) {
            throw AuthorizationException::forPermission('visa.override_status');
        }

        $to = $data['status'];
        $isOverride = $this->statuses->assert('visa', $visa->status, $to, $override);
        $reason = $data['reason'];
        if (($isOverride || in_array($to, ['rejected', 'cancelled'], true)) && $reason === null) {
            throw new ValidationException(['reason' => [$isOverride ? 'An override needs a reason.' : 'Please give a reason.']]);
        }

        $set = ['status' => $to];
        if ($data['visa_number'] !== null) {
            $set['visa_number'] = $data['visa_number'];
        }
        if ($to === 'submitted') {
            $set['submission_date'] = $data['submission_date'] ?? $visa->submissionDate ?? gmdate('Y-m-d');
        } elseif ($to === 'approved') {
            $approval = $data['approval_date'] ?? gmdate('Y-m-d');
            $expiry = $data['expiry_date'] ?? $visa->expiryDate;
            if ($expiry === null) {
                throw new ValidationException(['expiry_date' => ['Enter the visa expiry date when approving.']]);
            }
            if ($expiry <= $approval) {
                throw new ValidationException(['expiry_date' => ['The visa must expire after the approval date.']]);
            }
            $set['approval_date'] = $approval;
            $set['expiry_date'] = $expiry;
        }

        $scope = $this->scopes->resolve($actor);

        $updated = $this->db->transaction(function () use ($visa, $to, $set, $reason, $isOverride, $actor, $expectedVersion, $scope): VisaApplication {
            if ($this->visas->update($visa->id, $set, $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('visa', $visa->publicId);
            }
            $this->history->append($visa->id, $visa->status, $to, $isOverride, $reason !== null ? mb_substr($reason, 0, 255) : null, $actor->id);

            if ($to === 'approved' && $visa->applicationId !== null) {
                $app = $this->applications->findById($visa->applicationId, $scope);
                if ($app !== null && $app->status === 'visa_processing') {
                    $this->applicationService->advance($app->id, 'visa_approved', $actor, 'Visa approved');
                }
            }
            $this->audit->log(
                $isOverride ? 'status_overridden' : 'status_changed',
                'visa',
                'visa_application',
                $visa->id,
                ['status' => $visa->status],
                ['status' => $to],
                $reason,
                $actor,
            );

            return $this->reload($visa->id, $scope);
        });

        if (in_array($to, ['approved', 'rejected'], true) && $updated->assignedTo !== null && $updated->assignedTo !== $actor->id) {
            $this->notifications->notify(
                userId: $updated->assignedTo,
                type: 'visa_' . $to,
                title: 'Visa ' . $to . ": {$updated->candidateName}",
                body: trim(($updated->applicationNumber ?? '') . ' · ' . $updated->country, ' ·'),
                linkType: 'visa',
                linkId: $updated->id,
                linkFragment: null,
            );
        }

        return $updated;
    }

    /** Only a visa that was never started can be removed. */
    public function delete(VisaApplication $visa, User $actor): void
    {
        if (!$this->gate->forUser($actor)->allows('delete', $visa)) {
            throw AuthorizationException::forPermission('visa.delete');
        }
        if ($this->visas->deleteNotStarted($visa->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only a visa application that has not started can be deleted — cancel it instead.', []);
        }
        $this->audit->log('deleted', 'visa', 'visa_application', $visa->id, ['status' => $visa->status], null, null, $actor);
    }

    // ---- internals -------------------------------------------------

    private function requireCountry(string $code): void
    {
        if (!$this->db->exists('SELECT 1 FROM countries WHERE code = :c AND is_active = 1', ['c' => $code])) {
            throw new ValidationException(['country' => ['Choose a supported country.']]);
        }
    }

    private function reload(int $id, BranchScope $scope): VisaApplication
    {
        $visa = $this->visas->findById($id, $scope);
        if ($visa === null) {
            throw new \RuntimeException('Visa application vanished mid-operation.');
        }

        return $visa;
    }
}
