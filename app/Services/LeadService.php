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
use App\Models\Candidate;
use App\Models\Followup;
use App\Repositories\CandidateRepository;
use App\Repositories\LeadFollowupRepository;
use App\Repositories\LeadRepository;
use App\Repositories\PersonRepository;
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
        private readonly LeadFollowupRepository $followups,
        private readonly CandidateRepository $candidates,
        private readonly PersonRepository $persons,
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

    // ---- merge --------------------------------------------------

    /** Contact / qualification fields the user may pick from the loser. */
    private const MERGE_FIELDS = [
        'name', 'phone', 'alternate_phone', 'email', 'priority', 'assigned_to', 'source_id',
        'campaign', 'interested_country', 'interested_job', 'experience_years', 'qualification',
        'salary_expectation', 'salary_currency', 'city', 'state', 'gender', 'date_of_birth',
    ];

    /** @return list<string> */
    public static function mergeableFields(): array
    {
        return self::MERGE_FIELDS;
    }

    /**
     * Fold $loserPublicId into $survivor. Fields named in $take are copied from
     * the loser; other fields keep the survivor's value, except an empty
     * survivor field is backfilled from the loser. Notes and follow-ups move to
     * the survivor, a summary note is written, both leads are audited, and the
     * loser is soft-deleted pointing at the survivor.
     *
     * @param list<string> $take
     */
    public function mergeLeads(Lead $survivor, string $loserPublicId, array $take, User $actor, int $expectedVersion): Lead
    {
        if (!$this->gate->forUser($actor)->allows('merge', $survivor)) {
            throw AuthorizationException::forPermission('leads.merge');
        }

        $scope = $this->scopes->resolve($actor);
        $loser = $this->leads->findByPublicId($loserPublicId, $scope);

        if ($loser === null) {
            throw new ValidationException(['loser' => ['Pick a lead you can see to merge in.']]);
        }
        if ($loser->id === $survivor->id) {
            throw new ValidationException(['loser' => ['A lead cannot be merged into itself.']]);
        }
        if (!$survivor->isEditable() || !$loser->isEditable()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Converted or already-merged leads cannot be merged.', []);
        }
        if ($survivor->branchId !== $loser->branchId) {
            throw new ValidationException(['loser' => ['Both leads must be in the same branch to merge.']]);
        }

        $take = array_values(array_intersect($take, self::MERGE_FIELDS));
        $changes = $this->mergeFieldChanges($survivor, $loser, $take);

        if (array_key_exists('assigned_to', $changes) && $changes['assigned_to'] !== null) {
            $this->assertAssigneeValid((int) $changes['assigned_to'], $survivor->branchId);
        }

        $before = $this->snapshot($survivor);

        $fresh = $this->db->transaction(function () use ($survivor, $loser, $changes, $take, $expectedVersion, $scope, $actor, $before): Lead {
            if ($this->leads->update($survivor->id, $changes, $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('lead', $survivor->publicId);
            }

            $moved = $this->leads->reassignChildren($loser->id, $survivor->id);

            if ($this->leads->markMerged($loser->id, $survivor->id, $loser->recordVersion, $scope) === 0) {
                throw new StaleRecordException('lead', $loser->publicId);
            }

            $summary = "Merged in {$loser->leadNumber} — {$loser->name} · {$loser->phone}"
                . ". Moved {$moved['notes']} note(s), {$moved['followups']} follow-up(s)."
                . ($take !== [] ? ' Took from merged lead: ' . implode(', ', $take) . '.' : '');
            $this->db->insertRow('lead_notes', [
                'lead_id' => $survivor->id,
                'user_id' => $actor->id,
                'body'    => mb_substr($summary, 0, 5000),
            ]);

            $fresh = $this->leads->findById($survivor->id, $scope);
            $this->audit->log('merged', 'leads', 'lead', $survivor->id, $before, $this->snapshot($fresh),
                "merged in {$loser->leadNumber}", $actor);
            $this->audit->log('merged_into', 'leads', 'lead', $loser->id,
                ['status' => $loser->statusKey], ['merged_into' => $survivor->id],
                "merged into {$survivor->leadNumber}", $actor);

            return $fresh;
        });

        if ($fresh->assignedTo !== null && $fresh->assignedTo !== $survivor->assignedTo && $fresh->assignedTo !== $actor->id) {
            $this->notify->leadAssigned($fresh, $fresh->assignedTo, $actor->name);
        }

        return $fresh;
    }

    /**
     * @param list<string> $take
     * @return array<string,mixed>
     */
    private function mergeFieldChanges(Lead $survivor, Lead $loser, array $take): array
    {
        $s = $survivor->raw;
        $l = $loser->raw;
        $changes = [];

        foreach (self::MERGE_FIELDS as $field) {
            $sv = $s[$field] ?? null;
            $lv = $l[$field] ?? null;
            $survivorEmpty = $sv === null || $sv === '';

            if (in_array($field, $take, true)) {
                if ((string) $lv !== (string) $sv) {
                    $changes[$field] = $lv;
                }
            } elseif ($survivorEmpty && $lv !== null && $lv !== '') {
                $changes[$field] = $lv;
            }
        }

        $survNotes = trim((string) $survivor->notes);
        $loserNotes = trim((string) $loser->notes);
        if ($loserNotes !== '' && $loserNotes !== $survNotes) {
            $changes['notes'] = mb_substr(
                trim($survNotes . "\n\n--- from {$loser->leadNumber} ---\n" . $loserNotes),
                0,
                60000,
            );
        }

        return $changes;
    }

    /**
     * Likely duplicates of a lead, each enriched with the full record so the
     * merge picker can show a field-by-field comparison.
     *
     * @return list<array{summary:array<string,mixed>,full:?Lead}>
     */
    public function duplicatesFor(Lead $lead, User $actor): array
    {
        $scope = $this->scopes->resolve($actor);
        $rows = $this->leads->findLikelyDuplicates($scope, $lead->phone, $lead->alternatePhone, $lead->email, $lead->id);

        return array_map(
            fn (array $r): array => ['summary' => $r, 'full' => $this->leads->findById((int) $r['id'], $scope)],
            $rows,
        );
    }

    // ---- conversion -----------------------------------------------

    /**
     * Convert a lead to a candidate. Transactional: finds-or-creates the
     * person identity (matched by phone/email so the same individual never
     * gets a second one), creates the candidate (or, if that person already
     * has one — a repeat lead for someone already in the pipeline — links to
     * the existing candidate instead of erroring on the person/candidate
     * uniqueness constraint), moves the lead to `converted`, and audits both
     * sides. This creates only the minimal candidate record; the full profile
     * (education, experience, skills, documents) is a later phase.
     */
    public function convert(Lead $lead, User $actor, int $expectedVersion): Candidate
    {
        if (!$this->gate->forUser($actor)->allows('convert', $lead)) {
            throw AuthorizationException::forPermission('leads.convert');
        }

        $convertedStatusId = $this->leads->statusIdByKey('converted')
            ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The "converted" status is not configured.', []);

        $scope = $this->scopes->resolve($actor);
        $before = $this->snapshot($lead);

        $candidate = $this->db->transaction(function () use ($lead, $actor, $expectedVersion, $convertedStatusId, $scope, $before): Candidate {
            $person = $this->persons->findOrCreate([
                'full_name' => $lead->name,
                'gender' => $lead->gender,
                'date_of_birth' => $lead->dateOfBirth,
                'primary_phone' => $lead->phone,
                'alternate_phone' => $lead->alternatePhone,
                'email' => $lead->email,
                'city' => $lead->city,
                'state' => $lead->state,
            ]);

            $existingCandidateId = $this->candidates->findIdByPersonId($person['id']);
            $linkedExisting = $existingCandidateId !== null;

            $candidateId = $existingCandidateId ?? $this->candidates->create([
                'public_id' => Ulid::generate(),
                'candidate_number' => $this->sequences->next('candidate', 'CAND', 6),
                'person_id' => $person['id'],
                'branch_id' => $lead->branchId,
                'origin_lead_id' => $lead->id,
                'assigned_counselor' => $lead->assignedTo,
                'highest_qualification' => $lead->qualification,
                'total_experience_years' => $lead->experienceYears,
                'created_by' => $actor->id,
            ]);

            if ($this->leads->markConverted($lead->id, $candidateId, $convertedStatusId, $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('lead', $lead->publicId);
            }

            if ($linkedExisting) {
                $this->db->insertRow('lead_notes', [
                    'lead_id' => $lead->id,
                    'user_id' => $actor->id,
                    'body' => mb_substr('Converted — matched an existing candidate record for this person.', 0, 5000),
                ]);
            }

            $freshLead = $this->leads->findById($lead->id, $scope);
            $this->audit->log('converted', 'leads', 'lead', $lead->id, $before, $this->snapshot($freshLead),
                $linkedExisting ? 'linked to existing candidate' : null, $actor);

            $candidate = $this->candidates->findById($candidateId, $scope)
                ?? throw new \RuntimeException('Candidate vanished after creation.');

            if (!$linkedExisting) {
                $this->audit->log('created', 'candidates', 'candidate', $candidate->id, null,
                    ['candidate_number' => $candidate->candidateNumber, 'origin_lead_id' => $lead->id], null, $actor);
            }

            return $candidate;
        });

        return $candidate;
    }

    // ---- follow-ups ----------------------------------------------

    /**
     * Schedule a follow-up on a lead.
     *
     * @param array{due_date:string,due_time?:?string,channel:string,subject?:?string,assigned_to?:?int} $data
     */
    public function scheduleFollowup(Lead $lead, array $data, User $actor): Followup
    {
        if (!$this->gate->forUser($actor)->allows('followups.create') || !$this->gate->forUser($actor)->allows('view', $lead)) {
            throw AuthorizationException::forPermission('followups.create');
        }
        if (!$lead->isEditable()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This lead is converted — no new follow-ups.', []);
        }

        $dueDate = (string) ($data['due_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || $dueDate < gmdate('Y-m-d')) {
            throw new ValidationException(['due_date' => ['Choose today or a future date.']]);
        }

        $dueTime = null;
        if (($t = trim((string) ($data['due_time'] ?? ''))) !== '') {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                throw new ValidationException(['due_time' => ['Use a 24-hour time like 14:30.']]);
            }
            $dueTime = $t . ':00';
        }

        $channel = (string) ($data['channel'] ?? 'call');
        if (!in_array($channel, ['call', 'whatsapp', 'sms', 'email', 'meeting', 'other'], true)) {
            throw new ValidationException(['channel' => ['Pick a valid channel.']]);
        }

        $assigneeId = isset($data['assigned_to']) && (int) $data['assigned_to'] > 0
            ? (int) $data['assigned_to']
            : ($lead->assignedTo ?? $actor->id);
        $this->assertAssigneeValid($assigneeId, $lead->branchId);

        $subject = trim((string) ($data['subject'] ?? ''));
        $subject = $subject !== '' ? mb_substr($subject, 0, 200) : null;

        $id = $this->db->transaction(function () use ($lead, $actor, $assigneeId, $dueDate, $dueTime, $channel, $subject): int {
            $newId = $this->followups->create([
                'lead_id'     => $lead->id,
                'assigned_to' => $assigneeId,
                'branch_id'   => $lead->branchId,
                'due_date'    => $dueDate,
                'due_time'    => $dueTime,
                'channel'     => $channel,
                'subject'     => $subject,
                'created_by'  => $actor->id,
            ]);
            $this->audit->log('followup_scheduled', 'leads', 'lead', $lead->id, null, [
                'followup_id' => $newId, 'due_date' => $dueDate, 'channel' => $channel, 'assigned_to' => $assigneeId,
            ], null, $actor);

            return $newId;
        });

        if ($assigneeId !== $actor->id) {
            $this->notify->leadFollowupDue($assigneeId, $lead, $dueDate);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->followups->findInScope($id, $scope)
            ?? throw new \RuntimeException('Follow-up vanished after creation.');
    }

    /**
     * Complete a follow-up. Optionally records the outcome as a lead note and
     * schedules the next follow-up in one step.
     *
     * @param array{due_date:string,due_time?:?string,channel:string,subject?:?string,assigned_to?:?int}|null $next
     */
    public function completeFollowup(int $followupId, User $actor, string $outcome, bool $logAsNote = false, ?array $next = null): ?Followup
    {
        $scope = $this->scopes->resolve($actor);
        $followup = $this->followups->findInScope($followupId, $scope);
        if ($followup === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Follow-up not found.', [], 404);
        }
        $lead = $this->leads->findById($followup->leadId, $scope);
        if ($lead === null || !$this->gate->forUser($actor)->allows('view', $lead)) {
            throw AuthorizationException::forPermission('followups.complete');
        }
        if (!$this->gate->forUser($actor)->allows('followups.complete')) {
            throw AuthorizationException::forPermission('followups.complete');
        }
        if (!$followup->isPending()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That follow-up is already closed.', []);
        }

        $outcome = trim($outcome);
        if ($outcome === '') {
            throw new ValidationException(['outcome' => ['Say what happened.']]);
        }

        $this->db->transaction(function () use ($followup, $lead, $actor, $outcome, $logAsNote): void {
            if ($this->followups->markCompleted($followup->id, $outcome) === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That follow-up is already closed.', []);
            }
            if ($logAsNote) {
                $this->db->insertRow('lead_notes', [
                    'lead_id' => $lead->id,
                    'user_id' => $actor->id,
                    'body'    => mb_substr("Follow-up ({$followup->channelLabel()}): {$outcome}", 0, 5000),
                ]);
            }
            $this->audit->log('followup_completed', 'leads', 'lead', $lead->id, null, [
                'followup_id' => $followup->id, 'outcome' => mb_substr($outcome, 0, 255),
            ], null, $actor);
        });

        return $next !== null ? $this->scheduleFollowup($lead, $next, $actor) : null;
    }

    public function cancelFollowup(int $followupId, User $actor): void
    {
        $scope = $this->scopes->resolve($actor);
        $followup = $this->followups->findInScope($followupId, $scope);
        if ($followup === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Follow-up not found.', [], 404);
        }
        $lead = $this->leads->findById($followup->leadId, $scope);
        if ($lead === null
            || !$this->gate->forUser($actor)->allows('view', $lead)
            || !$this->gate->forUser($actor)->allows('followups.edit')) {
            throw AuthorizationException::forPermission('followups.edit');
        }

        $this->db->transaction(function () use ($followup, $lead, $actor): void {
            if ($this->followups->markCancelled($followup->id) === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That follow-up is already closed.', []);
            }
            $this->audit->log('followup_cancelled', 'leads', 'lead', $lead->id, null, ['followup_id' => $followup->id], null, $actor);
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
