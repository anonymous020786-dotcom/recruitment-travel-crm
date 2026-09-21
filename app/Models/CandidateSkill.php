<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One skill attached to a candidate (`candidate_skills` joined to the global
 * `skills` catalogue). Immutable read model.
 */
final class CandidateSkill
{
    public function __construct(
        public readonly int $candidateId,
        public readonly int $skillId,
        public readonly string $name,
        public readonly ?string $category,
        public readonly string $proficiency,
        public readonly ?float $years,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            candidateId: (int) $r['candidate_id'],
            skillId: (int) $r['skill_id'],
            name: (string) $r['name'],
            category: $r['category'] ?? null,
            proficiency: (string) $r['proficiency'],
            years: isset($r['years']) ? (float) $r['years'] : null,
        );
    }

    public function proficiencyLabel(): string
    {
        return ucfirst($this->proficiency);
    }
}
