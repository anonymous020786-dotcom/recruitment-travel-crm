<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Employer;
use App\Models\EmployerContact;
use App\Models\User;
use App\Repositories\EmployerContactRepository;
use App\Repositories\EmployerRepository;
use App\Support\Db;
use App\Support\Sequences;
use App\Support\Ulid;

/** Employer business workflows: create/edit/status/delete plus contact management. */
final class EmployerService
{
    public function __construct(
        private readonly Db $db,
        private readonly EmployerRepository $employers,
        private readonly EmployerContactRepository $contacts,
        private readonly Sequences $sequences,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, User $actor, ?int $branchId): Employer
    {
        if (!$this->gate->forUser($actor)->allows('employers.create')) {
            throw AuthorizationException::forPermission('employers.create');
        }

        $scope = $this->scopes->resolve($actor);
        $branchId ??= $actor->primaryBranchId;
        if ($branchId === null || !$scope->contains($branchId)) {
            throw new ValidationException(['branch_id' => ['Select a valid branch for this employer.']]);
        }
        if (($owner = $data['account_owner'] ?? null) !== null) {
            $this->assertOwnerValid((int) $owner, $branchId);
        }

        return $this->db->transaction(function () use ($data, $actor, $branchId, $scope): Employer {
            $id = $this->employers->create($data + [
                'public_id'       => Ulid::generate(),
                'employer_number' => $this->sequences->next('employer', 'EMP', 6),
                'branch_id'       => $branchId,
                'created_by'      => $actor->id,
            ]);
            $this->audit->log('created', 'employers', 'employer', $id, null, ['company_name' => $data['company_name']], null, $actor);

            $row = $this->employers->findById($id, $scope);
            if ($row === null) {
                throw new \RuntimeException('Employer vanished immediately after insert.');
            }

            return $row;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(Employer $employer, array $data, User $actor): Employer
    {
        $this->authorize('update', $employer, $actor, 'employers.edit');

        if (($owner = $data['account_owner'] ?? null) !== null && $employer->branchId !== null) {
            $this->assertOwnerValid((int) $owner, $employer->branchId);
        }

        $scope = $this->scopes->resolve($actor);
        $before = $this->snapshot($employer);

        return $this->db->transaction(function () use ($employer, $data, $scope, $actor, $before): Employer {
            $this->employers->update($employer->id, $data, $scope);
            $fresh = $this->employers->findById($employer->id, $scope);
            if ($fresh === null) {
                throw new \RuntimeException('Employer vanished mid-update.');
            }
            $this->audit->log('updated', 'employers', 'employer', $employer->id, $before, $this->snapshot($fresh), null, $actor);

            return $fresh;
        });
    }

    public function delete(Employer $employer, User $actor): void
    {
        $this->authorize('delete', $employer, $actor, 'employers.delete');

        $scope = $this->scopes->resolve($actor);
        if ($this->employers->softDelete($employer->id, $scope) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Employer not found.', [], 404);
        }
        $this->audit->log('deleted', 'employers', 'employer', $employer->id, $this->snapshot($employer), null, null, $actor);
    }

    /** @param array<string,mixed> $data name/designation/email/phone/is_primary */
    public function addContact(Employer $employer, array $data, User $actor): EmployerContact
    {
        $this->authorize('manageContacts', $employer, $actor, 'employers.contacts.manage');

        return $this->db->transaction(function () use ($employer, $data, $actor): EmployerContact {
            if ($data['is_primary']) {
                $this->contacts->clearPrimaryExcept($employer->id, null);
            }
            $id = $this->contacts->create($data + ['employer_id' => $employer->id]);
            $this->audit->log('contact_added', 'employers', 'employer', $employer->id, null, ['contact_id' => $id, 'name' => $data['name']], null, $actor);

            $row = $this->contacts->findInEmployer($id, $employer->id);
            if ($row === null) {
                throw new \RuntimeException('Contact vanished immediately after insert.');
            }

            return $row;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateContact(Employer $employer, int $contactId, array $data, User $actor): EmployerContact
    {
        $this->authorize('manageContacts', $employer, $actor, 'employers.contacts.manage');

        if ($this->contacts->findInEmployer($contactId, $employer->id) === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Contact not found.', [], 404);
        }

        return $this->db->transaction(function () use ($employer, $contactId, $data, $actor): EmployerContact {
            if ($data['is_primary']) {
                $this->contacts->clearPrimaryExcept($employer->id, $contactId);
            }
            $this->contacts->update($contactId, $employer->id, $data);
            $this->audit->log('contact_updated', 'employers', 'employer', $employer->id, null, ['contact_id' => $contactId], null, $actor);

            $row = $this->contacts->findInEmployer($contactId, $employer->id);
            if ($row === null) {
                throw new \RuntimeException('Contact vanished mid-update.');
            }

            return $row;
        });
    }

    public function removeContact(Employer $employer, int $contactId, User $actor): void
    {
        $this->authorize('manageContacts', $employer, $actor, 'employers.contacts.manage');

        if ($this->contacts->delete($contactId, $employer->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Contact not found.', [], 404);
        }
        $this->audit->log('contact_removed', 'employers', 'employer', $employer->id, ['contact_id' => $contactId], null, null, $actor);
    }

    // ---- internals -------------------------------------------------

    private function authorize(string $ability, Employer $employer, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $employer)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function assertOwnerValid(int $userId, int $branchId): void
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
            throw new ValidationException(['account_owner' => ['That person cannot own employer accounts in this branch.']]);
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(Employer $e): array
    {
        return [
            'company_name' => $e->companyName, 'country' => $e->country, 'status' => $e->status,
            'industry' => $e->industry, 'account_owner' => $e->accountOwner, 'license_expiry' => $e->licenseExpiry,
        ];
    }
}
