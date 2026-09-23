<?php

declare(strict_types=1);

namespace App\Models;

/** A candidate deployed to an employer (`placements` joined to candidate/application/employer/job). Immutable read model. */
final class Placement
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $candidateId,
        public readonly string $candidatePublicId,
        public readonly string $candidateName,
        public readonly string $candidateNumber,
        public readonly int $applicationId,
        public readonly string $applicationPublicId,
        public readonly string $applicationNumber,
        public readonly int $employerId,
        public readonly string $employerPublicId,
        public readonly string $employerName,
        public readonly int $jobId,
        public readonly string $jobTitle,
        public readonly int $branchId,
        public readonly string $placedOn,
        public readonly ?string $monthlySalary,
        public readonly ?string $currency,
        public readonly ?string $contractEnd,
        public readonly string $status,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            candidateId: (int) $r['candidate_id'],
            candidatePublicId: (string) ($r['candidate_public_id'] ?? ''),
            candidateName: (string) ($r['candidate_name'] ?? ''),
            candidateNumber: (string) ($r['candidate_number'] ?? ''),
            applicationId: (int) $r['application_id'],
            applicationPublicId: (string) ($r['application_public_id'] ?? ''),
            applicationNumber: (string) ($r['application_number'] ?? ''),
            employerId: (int) $r['employer_id'],
            employerPublicId: (string) ($r['employer_public_id'] ?? ''),
            employerName: (string) ($r['employer_name'] ?? ''),
            jobId: (int) $r['job_id'],
            jobTitle: (string) ($r['job_title'] ?? ''),
            branchId: (int) $r['branch_id'],
            placedOn: (string) $r['placed_on'],
            monthlySalary: isset($r['monthly_salary']) ? (string) $r['monthly_salary'] : null,
            currency: $r['currency'] ?? null,
            contractEnd: $r['contract_end'] ?? null,
            status: (string) $r['status'],
            createdAt: (string) $r['created_at'],
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}
