<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Candidate read model — a `candidates` row joined to its `persons` identity
 * and (optionally) the lead it was converted from. Immutable.
 *
 * The candidate/person split exists so one human identity can be shared across
 * leads, candidates, employer contacts, etc. This step only creates the
 * minimal record on conversion; the full profile (education, experience,
 * skills, passport, documents) is a later phase — see docs/phase-2/STEP-2.6.
 */
final class Candidate
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $candidateNumber,
        public readonly int $personId,
        public readonly int $branchId,
        public readonly ?int $originLeadId,
        public readonly ?string $originLeadNumber,
        public readonly ?string $originLeadPublicId,
        public readonly string $stage,
        public readonly ?string $maritalStatus,
        public readonly ?string $currentCountry,
        public readonly ?string $highestQualification,
        public readonly ?float $totalExperienceYears,
        public readonly ?int $assignedCounselor,
        public readonly ?string $counselorName,
        public readonly bool $isActive,
        public readonly int $recordVersion,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        // Joined person identity.
        public readonly string $fullName,
        public readonly ?string $gender,
        public readonly ?string $dateOfBirth,
        public readonly ?string $primaryPhone,
        public readonly ?string $alternatePhone,
        public readonly ?string $email,
        public readonly ?string $nationality,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $country,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            candidateNumber: (string) $r['candidate_number'],
            personId: (int) $r['person_id'],
            branchId: (int) $r['branch_id'],
            originLeadId: isset($r['origin_lead_id']) ? (int) $r['origin_lead_id'] : null,
            originLeadNumber: $r['origin_lead_number'] ?? null,
            originLeadPublicId: $r['origin_lead_public_id'] ?? null,
            stage: (string) $r['stage'],
            maritalStatus: $r['marital_status'] ?? null,
            currentCountry: $r['current_country'] ?? null,
            highestQualification: $r['highest_qualification'] ?? null,
            totalExperienceYears: isset($r['total_experience_years']) ? (float) $r['total_experience_years'] : null,
            assignedCounselor: isset($r['assigned_counselor']) ? (int) $r['assigned_counselor'] : null,
            counselorName: $r['counselor_name'] ?? null,
            isActive: (bool) ($r['is_active'] ?? true),
            recordVersion: (int) ($r['record_version'] ?? 1),
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
            fullName: (string) $r['full_name'],
            gender: $r['gender'] ?? null,
            dateOfBirth: $r['date_of_birth'] ?? null,
            primaryPhone: $r['primary_phone'] ?? null,
            alternatePhone: $r['alternate_phone'] ?? null,
            email: $r['email'] ?? null,
            nationality: $r['nationality'] ?? null,
            city: $r['city'] ?? null,
            state: $r['state'] ?? null,
            country: $r['country'] ?? null,
        );
    }

    public function stageLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->stage));
    }
}
