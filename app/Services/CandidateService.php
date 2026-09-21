<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Candidate;
use App\Models\CandidateEducation;
use App\Models\CandidateExperience;
use App\Models\CandidatePreferences;
use App\Models\CandidateSkill;
use App\Models\Passport;
use App\Models\User;
use App\Repositories\CandidateEducationRepository;
use App\Repositories\CandidateExperienceRepository;
use App\Repositories\CandidatePreferencesRepository;
use App\Repositories\CandidateRepository;
use App\Repositories\CandidateSkillRepository;
use App\Repositories\PassportRepository;
use App\Repositories\PersonRepository;
use App\Repositories\SkillRepository;
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
        private readonly CandidateEducationRepository $education,
        private readonly CandidateExperienceRepository $experience,
        private readonly SkillRepository $skills,
        private readonly CandidateSkillRepository $candidateSkills,
        private readonly CandidatePreferencesRepository $preferences,
        private readonly PassportRepository $passports,
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

    /** @param array<string,mixed> $data */
    public function addEducation(Candidate $candidate, array $data, User $actor): CandidateEducation
    {
        $this->authorize('manageEducation', $candidate, $actor, 'candidates.education.manage');

        $id = $this->education->create($data + ['candidate_id' => $candidate->id]);
        $this->audit->log('education_added', 'candidates', 'candidate', $candidate->id, null, $data, null, $actor);

        $row = $this->education->findInCandidate($id, $candidate->id);
        if ($row === null) {
            throw new \RuntimeException('Education row vanished immediately after insert.');
        }

        return $row;
    }

    /** @param array<string,mixed> $data */
    public function updateEducation(Candidate $candidate, int $educationId, array $data, User $actor): CandidateEducation
    {
        $this->authorize('manageEducation', $candidate, $actor, 'candidates.education.manage');

        $existing = $this->education->findInCandidate($educationId, $candidate->id);
        if ($existing === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Education record not found.', [], 404);
        }

        $this->education->update($educationId, $candidate->id, $data);
        $this->audit->log('education_updated', 'candidates', 'candidate', $candidate->id, null, $data, null, $actor);

        $row = $this->education->findInCandidate($educationId, $candidate->id);
        if ($row === null) {
            throw new \RuntimeException('Education row vanished immediately after update.');
        }

        return $row;
    }

    public function removeEducation(Candidate $candidate, int $educationId, User $actor): void
    {
        $this->authorize('manageEducation', $candidate, $actor, 'candidates.education.manage');

        if ($this->education->delete($educationId, $candidate->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Education record not found.', [], 404);
        }
        $this->audit->log('education_removed', 'candidates', 'candidate', $candidate->id, ['education_id' => $educationId], null, null, $actor);
    }

    /** @param array<string,mixed> $data */
    public function addExperience(Candidate $candidate, array $data, User $actor): CandidateExperience
    {
        $this->authorize('manageExperience', $candidate, $actor, 'candidates.experience.manage');

        $id = $this->experience->create($data + ['candidate_id' => $candidate->id]);
        $this->audit->log('experience_added', 'candidates', 'candidate', $candidate->id, null, $data, null, $actor);

        $row = $this->experience->findInCandidate($id, $candidate->id);
        if ($row === null) {
            throw new \RuntimeException('Experience row vanished immediately after insert.');
        }

        return $row;
    }

    /** @param array<string,mixed> $data */
    public function updateExperience(Candidate $candidate, int $experienceId, array $data, User $actor): CandidateExperience
    {
        $this->authorize('manageExperience', $candidate, $actor, 'candidates.experience.manage');

        $existing = $this->experience->findInCandidate($experienceId, $candidate->id);
        if ($existing === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Experience record not found.', [], 404);
        }

        $this->experience->update($experienceId, $candidate->id, $data);
        $this->audit->log('experience_updated', 'candidates', 'candidate', $candidate->id, null, $data, null, $actor);

        $row = $this->experience->findInCandidate($experienceId, $candidate->id);
        if ($row === null) {
            throw new \RuntimeException('Experience row vanished immediately after update.');
        }

        return $row;
    }

    public function removeExperience(Candidate $candidate, int $experienceId, User $actor): void
    {
        $this->authorize('manageExperience', $candidate, $actor, 'candidates.experience.manage');

        if ($this->experience->delete($experienceId, $candidate->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Experience record not found.', [], 404);
        }
        $this->audit->log('experience_removed', 'candidates', 'candidate', $candidate->id, ['experience_id' => $experienceId], null, null, $actor);
    }

    /** @param array<string,mixed> $data skill_name/category/proficiency/years */
    public function addSkill(Candidate $candidate, array $data, User $actor): CandidateSkill
    {
        $this->authorize('manageSkills', $candidate, $actor, 'candidates.skills.manage');

        $skillId = $this->skills->findOrCreateByName((string) $data['skill_name'], $data['category'] ?? null);
        $this->candidateSkills->attach($candidate->id, $skillId, (string) ($data['proficiency'] ?? 'intermediate'), $data['years'] ?? null);
        $this->audit->log('skill_added', 'candidates', 'candidate', $candidate->id, null, ['skill_id' => $skillId] + $data, null, $actor);

        foreach ($this->candidateSkills->forCandidate($candidate->id) as $row) {
            if ($row->skillId === $skillId) {
                return $row;
            }
        }

        throw new \RuntimeException('Skill row vanished immediately after attach.');
    }

    public function removeSkill(Candidate $candidate, int $skillId, User $actor): void
    {
        $this->authorize('manageSkills', $candidate, $actor, 'candidates.skills.manage');

        if ($this->candidateSkills->detach($candidate->id, $skillId) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Skill not found on this candidate.', [], 404);
        }
        $this->audit->log('skill_removed', 'candidates', 'candidate', $candidate->id, ['skill_id' => $skillId], null, null, $actor);
    }

    /**
     * @param array<string,mixed> $data preferred_countries/preferred_job_titles (lists)
     *                                   plus the scalar candidate_preferences columns
     */
    public function savePreferences(Candidate $candidate, array $data, User $actor): CandidatePreferences
    {
        $this->authorize('managePreferences', $candidate, $actor, 'candidates.preferences.manage');

        $row = [
            'preferred_countries'  => json_encode($data['preferred_countries'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'preferred_job_titles' => json_encode($data['preferred_job_titles'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'min_expected_salary'  => $data['min_expected_salary'] ?? null,
            'salary_currency'      => $data['salary_currency'] ?? null,
            'willing_to_relocate'  => (int) ($data['willing_to_relocate'] ?? true),
            'available_from'       => $data['available_from'] ?? null,
            'passport_ready'       => (int) ($data['passport_ready'] ?? false),
            'notes'                => $data['notes'] ?? null,
        ];

        $this->preferences->upsert($candidate->id, $row);
        $this->audit->log('preferences_saved', 'candidates', 'candidate', $candidate->id, null, $data, null, $actor);

        $fresh = $this->preferences->find($candidate->id);
        if ($fresh === null) {
            throw new \RuntimeException('Preferences row vanished immediately after upsert.');
        }

        return $fresh;
    }

    /** @param array<string,mixed> $data */
    public function addPassport(Candidate $candidate, array $data, User $actor): Passport
    {
        $this->authorize('managePassport', $candidate, $actor, 'candidates.passport.manage');
        $this->assertPassportNumberFree((string) $data['passport_number'], null);

        return $this->db->transaction(function () use ($candidate, $data, $actor): Passport {
            if ($data['is_primary'] ?? false) {
                $this->passports->clearPrimaryExcept($candidate->id, null);
            }
            $id = $this->passports->create($data + ['candidate_id' => $candidate->id]);
            $this->audit->log('passport_added', 'candidates', 'candidate', $candidate->id, null, ['passport_id' => $id] + $data, null, $actor);

            $row = $this->passports->findInCandidate($id, $candidate->id);
            if ($row === null) {
                throw new \RuntimeException('Passport row vanished immediately after insert.');
            }

            return $row;
        });
    }

    /** @param array<string,mixed> $data */
    public function updatePassport(Candidate $candidate, int $passportId, array $data, User $actor): Passport
    {
        $this->authorize('managePassport', $candidate, $actor, 'candidates.passport.manage');

        $existing = $this->passports->findInCandidate($passportId, $candidate->id);
        if ($existing === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Passport record not found.', [], 404);
        }
        $this->assertPassportNumberFree((string) $data['passport_number'], $passportId);

        return $this->db->transaction(function () use ($candidate, $passportId, $data, $actor): Passport {
            if ($data['is_primary'] ?? false) {
                $this->passports->clearPrimaryExcept($candidate->id, $passportId);
            }
            $this->passports->update($passportId, $candidate->id, $data);
            $this->audit->log('passport_updated', 'candidates', 'candidate', $candidate->id, null, $data, null, $actor);

            $row = $this->passports->findInCandidate($passportId, $candidate->id);
            if ($row === null) {
                throw new \RuntimeException('Passport row vanished immediately after update.');
            }

            return $row;
        });
    }

    public function removePassport(Candidate $candidate, int $passportId, User $actor): void
    {
        $this->authorize('managePassport', $candidate, $actor, 'candidates.passport.manage');

        if ($this->passports->delete($passportId, $candidate->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Passport record not found.', [], 404);
        }
        $this->audit->log('passport_removed', 'candidates', 'candidate', $candidate->id, ['passport_id' => $passportId], null, null, $actor);
    }

    // ---- internals -------------------------------------------------

    private function assertPassportNumberFree(string $number, ?int $excludeId): void
    {
        if ($this->passports->numberTaken($number, $excludeId)) {
            throw new ValidationException(['passport_number' => ['This passport number is already on file for another candidate.']]);
        }
    }

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
