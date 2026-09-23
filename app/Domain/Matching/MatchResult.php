<?php

declare(strict_types=1);

namespace App\Domain\Matching;

/**
 * The explainable outcome of matching one candidate to one job.
 *
 * `criteria` rows: key, label, weight, ratio (0–1, null when n/a), state
 * (matched|partial|missing|na) and a human-readable detail. `toArray()` is the
 * shape stored later in applications.match_breakdown.
 */
final class MatchResult
{
    /**
     * @param list<array{key:string,label:string,weight:int,ratio:?float,state:string,detail:string}> $criteria
     * @param list<string> $missingMandatory labels of unmet mandatory requirements
     */
    public function __construct(
        public readonly float $score,
        public readonly bool $eligible,
        public readonly array $criteria,
        public readonly array $missingMandatory,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function matched(): array
    {
        return array_values(array_filter($this->criteria, static fn (array $c): bool => $c['state'] === 'matched'));
    }

    /** @return list<array<string,mixed>> */
    public function missing(): array
    {
        return array_values(array_filter($this->criteria, static fn (array $c): bool => in_array($c['state'], ['missing', 'partial'], true)));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'eligible' => $this->eligible,
            'missing_mandatory' => $this->missingMandatory,
            'criteria' => $this->criteria,
        ];
    }
}
