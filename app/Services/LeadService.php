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
use App\Models\Lead;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\LeadRepository;
use App\Support\Db;
use App\Support\Sequences;
use App\Support\Ulid;

/**
 * Lead business workflows. Controllers call exactly one method here; all DB
 * transactions, audit entries, notifications and invariant checks live here.
 */
final class LeadService
{
    public function __construct(
        private readonly Db $db,
        private readonly LeadRepository $leads,
        private readonly Sequences $sequences,
        private readonly StatusMachine $statuses,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly NotificationService $notify,
        private readonly BranchScopeResolver $scopes,
    ) {
    }

    /**
     * @param array<string,mixed> $data validated writable fields
     * @throws DomainRuleException DUPLICATE_LEAD (context: list of matches) when unconfirmed
     */
    public function create(array $data, User $actor, ?int $branchId, bool $confirmedNotDuplicate = false): Lead
    {
        if (!$this->gate->forUser($actor)->allows('leads.create')) {
            throw AuthorizationException::forPermission('leads.create');
        }

        $scope = $this->scopes->resolve($actor);
        $branchId ??= $actor->primaryBranchId;
        if ($branchId === null || !$scope->contains($branchId)) {
            throw new ValidationException(['branch_id' => ['Select a valid branch for this lead.']]);
        }

        if (($assigneeId = $data['assigned_to'] ?? null) !== null) {
            $this->assertAssigneeValid((int) $assigneeId, $branchId);
        }

        $dupes = $this->leads->findLikelyDuplicates(
            $scope,
            $data['phone'] ?? null,
            $data['alternate_phone'] ?? null,
            $data['email'] ?? null,
        );
        if ($dupes !== [] && !$confirmedNotDuplicate) {
            throw new DomainRuleException(
                DomainRuleException::DUPLICATE_LEAD,
                'A lead with this phone or email already exists.',
                ['duplicates' => $dupes],
                409,
            );
        }

        $statusId = $this->leads->defaultStatusId();

        $lead = $this->db->transaction(function () use ($data, $actor, $branchId, $statusId): Lead {
            $number = $this->sequences->next('lead', 'LEAD', 6);

            $row = $this->onlyColumns($data) + [
                'public_id'   => Ulid::generate(),
                'lead_number' => $number,
                'branch_id'   => $branchId,
                'status_id'   => $statusId,
                'created_by'  => $actor->id,
            ];
            $id = $this->leads->insert($row);

            $lead = $this->leads->findById($id, $this->scopes->resolve($actor));
            $this->audit->log('created', 'leads', 'lead', $id, null, $this->snapshot($lead), null, $actor);

            return $lead;
        });

        if ($lead->assignedTo !== null && $lead->assignedTo !== $actor->id) {
            $this->notify->leadAssigned($lead, $lead->assignedTo, $actor->name);
        }

        return $lead;
    }

    /** @param array<string,mixed> $data validated writable fields */
    public function update(Lead $lead, array $data, User $actor, int $expectedVersion): Lead
    {
        $this->authorize('update', $lead, $actor, 'leads.edit');

        $changes = $this->onlyColumns($data);
        if (($assigneeId = $changes['assigned_to'] ?? null) !== null && (int) $assigneeId !== $lead->assignedTo) {
            $this->assertAssigneeValid((int) $assigneeId, $lead->branchId);
        }

        $scope = $this->scopes->resolve($actor);
        $before = $this->snapshot($lead);

        $updated = $this->db->transaction(function () use ($lead, $changes, $expectedVersion, $scope, $actor, $before): Lead {
            $affected = $this->leads->update($lead->id, $changes, $expectedVersion, $scope);
            if ($affected === 0) {
                throw new StaleRecordException('lead', $lead->publicId);
            }

            $fresh = $this->leads->findById($lead->id, $scope);
            $this->audit->log('updated', 'leads', 'lead', $lead->id, $before, $this->snapshot($fresh), null, $actor);

            return $fresh;
        });

        if ($updated->assignedTo !== null && $updated->assignedTo !== $lead->assignedTo && $updated->assignedTo !== $actor->id) {
            $this->notify->leadAssigned($updated, $updated->assignedTo, $actor->name);
        }

        return $updated;
    }

    public function assign(Lead $lead, ?int $assigneeId, User $actor, int $expectedVersion): Lead
    {
        if (!$this->gate->forUser($actor)->allows('assign', $lead)) {
            throw AuthorizationException::forPermission('leads.assign');
        }
        if ($assigneeId !== null) {
            $this->assertAssigneeValid($assigneeId, $lead->branchId);
        }

        $scope = $this->scopes->resolve($actor);

        $fresh = $this->db->transaction(function () use ($lead, $assigneeId, $expectedVersion, $scope, $actor): Lead {
            $affected = $this->leads->update($lead->id, ['assigned_to' => $assigneeId], $expectedVersion, $scope);
            if ($affected === 0) {
                throw new StaleRecordException('lead', $lead->publicId);
            }
            $fresh = $this->leads->findById($lead->id, $scope);
            $this->audit->log(
                'assigned',
                'leads',
                'lead',
                $lead->id,
                ['assigned_to' => $lead->assignedTo],
                ['assigned_to' => $assigneeId],
                null,
                $actor,
            );

            return $fresh;
        });

        if ($assigneeId !== null && $assigneeId !== $actor->id) {
            $this->notify->leadAssigned($fresh, $assigneeId, $actor->name);
        }

        return $fresh;
    }

    /** @param list<string> $publicIds @return int number reassigned */
    public function bulkAssign(array $publicIds, ?int $assigneeId, User $actor): int
    {
        if (!$this->gate->forUser($actor)->allows('leads.assign')) {
            throw AuthorizationException::forPermission('leads.assign');
        }

        $scope = $this->scopes->resolve($actor);
        $ids = [];
        $branchIds = [];
        foreach (array_slice($publicIds, 0, 500) as $pid) {
            $lead = $this->leads->findByPublicId((string) $pid, $scope);
            if ($lead !== null && $lead->isEditable()) {
                $ids[] = $lead->id;
                $branchIds[$lead->branchId] = true;
            }
        }
        if ($ids === []) {
            return 0;
        }

        if ($assigneeId !== null) {
            foreach (array_keys($branchIds) as $branchId) {
                $this->assertAssigneeValid($assigneeId, (int) $branchId);
            }
        }

        $count = $this->db->transaction(function () use ($ids, $assigneeId, $scope, $actor): int {
            $n = $this->leads->bulkAssign($ids, $assigneeId, $scope);
            $this->audit->log(
                'bulk_assigned',
                'leads',
                'lead',
                null,
                null,
                ['lead_ids' => $ids, 'assigned_to' => $assigneeId, 'count' => $n],
                'bulk reassignment',
                $actor,
            );

            return $n;
        });

        if ($assigneeId !== null && $assigneeId !== $actor->id) {
            foreach ($ids as $id) {
                $lead = $this->leads->findById($id, $scope);
                if ($lead !== null) {
                    $this->notify->leadAssigned($lead, $assigneeId, $actor->name);
                }
            }
        }

        return $count;
    }

    /**
     * Manual pipeline move. Strictly follows the StatusMachine — there is no
     * override for leads. `converted` is reached only through convert().
     * `lost` / `not_interested` capture a reason.
     */
    public function changeStatus(Lead $lead, string $toKey, User $actor, int $expectedVersion, ?string $reason = null): Lead
    {
        $this->authorize('changeStatus', $lead, $actor, 'leads.edit');

        if ($toKey === 'converted') {
            throw new DomainRuleException(
                DomainRuleException::RULE_VIOLATION,
                'Use "Convert to candidate" to mark a lead as converted.',
                [],
            );
        }

        $this->statuses->assert('lead', $lead->statusKey, $toKey); // throws on an invalid move

        if (in_array($toKey, ['lost', 'not_interested'], true) && ($reason === null || trim($reason) === '')) {
            throw new ValidationException(['reason' => ['Please give a reason.']]);
        }

        $toStatusId = $this->leads->statusIdByKey($toKey)
            ?? throw new ValidationException(['status' => ['Unknown lead status.']]);

        $scope = $this->scopes->resolve($actor);
        $changes = ['status_id' => $toStatusId];
        if (in_array($toKey, ['lost', 'not_interested'], true)) {
            $changes['lost_reason'] = trim((string) $reason);
        }

        return $this->db->transaction(function () use ($lead, $changes, $expectedVersion, $scope, $actor, $toKey, $reason): Lead {
            $affected = $this->leads->update($lead->id, $changes, $expectedVersion, $scope);
            if ($affected === 0) {
                throw new StaleRecordException('lead', $lead->publicId);
            }
            $fresh = $this->leads->findById($lead->id, $scope);
            $this->audit->log(
                'status_changed',
                'leads',
                'lead',
                $lead->id,
                ['status' => $lead->statusKey],
                ['status' => $toKey],
                $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                $actor,
            );

            return $fresh;
        });
    }

    public function addNote(Lead $lead, string $body, User $actor): void
    {
        if (!$this->gate->forUser($actor)->allows('addNote', $lead)) {
            throw AuthorizationException::forPermission('leads.edit');
        }
        $body = trim($body);
        if ($body === '') {
            throw new ValidationException(['body' => ['The note cannot be empty.']]);
        }

        $this->db->transaction(function () use ($lead, $body, $actor): void {
            $noteId = $this->db->insertRow('lead_notes', [
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'body'    => mb_substr($body, 0, 5000),
            ]);
            $this->audit->log('note_added', 'leads', 'lead', $lead->id, null, ['note_id' => $noteId], null, $actor);
        });
    }

    public function delete(Lead $lead, User $actor, int $expectedVersion): void
    {
        if (!$this->gate->forUser($actor)->allows('delete', $lead)) {
            throw AuthorizationException::forPermission('leads.delete');
        }
        if ($lead->isConverted()) {
            throw new DomainRuleException(
                DomainRuleException::RULE_VIOLATION,
                'A converted lead cannot be deleted.',
                [],
            );
        }

        $scope = $this->scopes->resolve($actor);
        $before = $this->snapshot($lead);

        $this->db->transaction(function () use ($lead, $expectedVersion, $scope, $actor, $before): void {
            $affected = $this->leads->softDelete($lead->id, $expectedVersion, $scope);
            if ($affected === 0) {
                throw new StaleRecordException('lead', $lead->publicId);
            }
            $this->audit->log('deleted', 'leads', 'lead', $lead->id, $before, null, 'soft delete', $actor);
        });
    }

    // ---- internals -------------------------------------------------

    private function authorize(string $ability, Lead $lead, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $lead)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function assertAssigneeValid(int $userId, int $branchId): void
    {
        $row = $this->db->selectOne(
            'SELECT u.id, u.is_active, u.is_org_wide, u.primary_branch_id,
                    EXISTS(SELECT 1 FROM user_branches ub WHERE ub.user_id = u.id AND ub.branch_id = :b) AS in_branch
             FROM users u WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $userId, 'b' => $branchId],
        );

        $ok = $row !== null
            && (bool) $row['is_active']
            && ((bool) $row['is_org_wide']
                || (int) ($row['primary_branch_id'] ?? 0) === $branchId
                || (bool) $row['in_branch']);

        if (!$ok) {
            throw new ValidationException(['assigned_to' => ['That person cannot be assigned leads in this branch.']]);
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> only real lead columns */
    private function onlyColumns(array $data): array
    {
        $allowed = [
            'name', 'phone', 'alternate_phone', 'email', 'gender', 'date_of_birth', 'city', 'state',
            'source_id', 'campaign', 'interested_country', 'interested_job', 'experience_years',
            'qualification', 'salary_expectation', 'salary_currency', 'priority', 'assigned_to', 'notes',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    /** @return array<string,mixed> audit-friendly snapshot */
    private function snapshot(Lead $lead): array
    {
        return [
            'name' => $lead->name, 'phone' => $lead->phone, 'alternate_phone' => $lead->alternatePhone,
            'email' => $lead->email, 'status' => $lead->statusKey, 'priority' => $lead->priority,
            'assigned_to' => $lead->assignedTo, 'source_id' => $lead->sourceId,
            'interested_country' => $lead->interestedCountry, 'interested_job' => $lead->interestedJob,
        ];
    }
}
