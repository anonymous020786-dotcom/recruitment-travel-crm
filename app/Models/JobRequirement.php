<?php

declare(strict_types=1);

namespace App\Models;

/** One requirement on a job (`job_requirements`); `skillId` links it to the skills catalogue when the label matched one. */
final class JobRequirement
{
    public function __construct(
        public readonly int $id,
        public readonly int $jobId,
        public readonly ?int $skillId,
        public readonly string $label,
        public readonly bool $isMandatory,
        public readonly int $weight,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            jobId: (int) $r['job_id'],
            skillId: isset($r['skill_id']) ? (int) $r['skill_id'] : null,
            label: (string) $r['label'],
            isMandatory: (bool) $r['is_mandatory'],
            weight: (int) $r['weight'],
        );
    }
}
