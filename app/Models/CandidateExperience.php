<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One employment record on a candidate profile. Immutable read model,
 * hydrated from `candidate_experience`.
 */
final class CandidateExperience
{
    public function __construct(
        public readonly int $id,
        public readonly int $candidateId,
        public readonly string $employerName,
        public readonly string $jobTitle,
        public readonly ?string $country,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly bool $isCurrent,
        public readonly ?string $responsibilities,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            candidateId: (int) $r['candidate_id'],
            employerName: (string) $r['employer_name'],
            jobTitle: (string) $r['job_title'],
            country: $r['country'] ?? null,
            startDate: $r['start_date'] ?? null,
            endDate: $r['end_date'] ?? null,
            isCurrent: (bool) ($r['is_current'] ?? false),
            responsibilities: $r['responsibilities'] ?? null,
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
        );
    }

    public function durationLabel(): string
    {
        $end = $this->isCurrent ? 'Present' : ($this->endDate ?? '—');

        return ($this->startDate ?? '—') . ' – ' . $end;
    }
}
