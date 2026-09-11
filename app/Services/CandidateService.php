<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Exceptions\AuthorizationException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\CandidateRepository;
use App\Repositories\PersonRepository;
use App\Support\Db;

/**
 * Candidate business workflows. Controllers call exactly one method here; all
 * DB transactions, audit entries and invariant checks live here.
 *
 * A candidate's editable profile spans two tables (persons: shared identity,
 * candidates: recruitment-specific fields) — every write here is one
 * transaction across both, optimistically locked on candidates.record_version
 * (a person row has no version of its own; the candidate is the aggregate
 * root for this purpose).
 */
final class CandidateService
{
    public function __construct(
        private readonly Db $db,
        private readonly CandidateRepository $candidates,
        private readonly PersonRepository $persons,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
    ) {
    }

    /**
     * @param array<string,mixed> $personData full_name/gender/date_of_birth/primary_phone/alternate_phone/email/nationality/city/state/country
     * @param array<string,mixed> $candidateData marital_status/current_country/highest_qualification/total_experience_years
     */
    public function updateProfile(Candidate $candidate, array $personData, array $candidateData, User $actor, int $expectedVersion): Candidate
    {
        $this->authorize('update', $candidate, $actor, 'candidates.edit');

        $scope = $this->scopes->resolve($actor);
        $before = $this->snapshot($candidate);

        return $this->db->transaction(function () use ($candidate, $personData, $candidateData, $expectedVersion, $scope, $actor, $before): Candidate {
            $this->persons->update($candidate->personId, $this->onlyPersonColumns($personData));

            $affected = $this->candidates->updateFields($candidate->id, $this->onlyCandidateColumns($candidateData), $expectedVersion, $scope);
            if ($affected === 0) {
                throw new StaleRecordException('candidate', $candidate->publicId);
            }

            $fresh = $this->candidates->findById($candidate->id, $scope);
            if ($fresh === null) {
                throw new \RuntimeException('Candidate vanished mid-update.');
            }
            $this->audit->log('updated', 'candidates', 'candidate', $candidate->id, $before, $this->snapshot($fresh), null, $actor);

            return $fresh;
        });
    }

    public function reassignCounselor(Candidate $candidate, ?int $counselorId, User $actor, int $expectedVersion): Candidate
    {
        $this->authorize('update', $candidate, $actor, 'candidates.edit');
        if ($counselorId !== null) {
            $this->assertCounselorValid($counselorId, $candidate->branchId);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($candidate, $counselorId, $expectedVersion, $scope, $actor): Candidate {
            $affected = $this->candidates->updateFields($candidate->id, ['assigned_counselor' => $counselorId], $expectedVersion, $scope);
            if ($affected === 0) {
                throw new StaleRecordException('candidate', $candidate->publicId);
            }
            $fresh = $this->candidates->findById($candidate->id, $scope);
            if ($fresh === null) {
                throw new \RuntimeException('Candidate vanished mid-update.');
            }
            $this->audit->log(
                'counselor_assigned',
                'candidates',
                'candidate',
                $candidate->id,
                ['assigned_counselor' => $candidate->assignedCounselor],
                ['assigned_counselor' => $counselorId],
                null,
                $actor,
            );

            return $fresh;
        });
    }

    // ---- internals -------------------------------------------------

    private function authorize(string $ability, Candidate $candidate, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $candidate)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function assertCounselorValid(int $userId, int $branchId): void
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
            throw new ValidationException(['assigned_counselor' => ['That person cannot be assigned candidates in this branch.']]);
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> only real persons columns */
    private function onlyPersonColumns(array $data): array
    {
        $allowed = [
            'full_name', 'gender', 'date_of_birth', 'primary_phone', 'alternate_phone',
            'email', 'nationality', 'city', 'state', 'country',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> only real candidates columns */
    private function onlyCandidateColumns(array $data): array
    {
        $allowed = ['marital_status', 'current_country', 'highest_qualification', 'total_experience_years'];

        return array_intersect_key($data, array_flip($allowed));
    }

    /** @return array<string,mixed> audit-friendly snapshot */
    private function snapshot(Candidate $candidate): array
    {
        return [
            'full_name' => $candidate->fullName, 'primary_phone' => $candidate->primaryPhone,
            'email' => $candidate->email, 'marital_status' => $candidate->maritalStatus,
            'current_country' => $candidate->currentCountry, 'highest_qualification' => $candidate->highestQualification,
            'total_experience_years' => $candidate->totalExperienceYears, 'assigned_counselor' => $candidate->assignedCounselor,
        ];
    }
}
