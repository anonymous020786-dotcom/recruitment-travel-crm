<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One education record on a candidate profile. Immutable read model,
 * hydrated from `candidate_education`.
 */
final class CandidateEducation
{
    public function __construct(
        public readonly int $id,
        public readonly int $candidateId,
        public readonly string $level,
        public readonly ?string $institution,
        public readonly ?string $boardUniversity,
        public readonly ?string $fieldOfStudy,
        public readonly ?int $startYear,
        public readonly ?int $endYear,
        public readonly ?string $grade,
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
            level: (string) $r['level'],
            institution: $r['institution'] ?? null,
            boardUniversity: $r['board_university'] ?? null,
            fieldOfStudy: $r['field_of_study'] ?? null,
            startYear: isset($r['start_year']) ? (int) $r['start_year'] : null,
            endYear: isset($r['end_year']) ? (int) $r['end_year'] : null,
            grade: $r['grade'] ?? null,
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
        );
    }
}
