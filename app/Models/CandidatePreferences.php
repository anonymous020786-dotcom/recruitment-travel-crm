<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A candidate's single-row job/travel preferences (`candidate_preferences`,
 * PK = candidate_id). Immutable read model; `null` from `find()` means the
 * candidate has never saved preferences yet.
 */
final class CandidatePreferences
{
    /**
     * @param list<string> $preferredCountries
     * @param list<string> $preferredJobTitles
     */
    public function __construct(
        public readonly int $candidateId,
        public readonly array $preferredCountries,
        public readonly array $preferredJobTitles,
        public readonly ?float $minExpectedSalary,
        public readonly ?string $salaryCurrency,
        public readonly bool $willingToRelocate,
        public readonly ?string $availableFrom,
        public readonly bool $passportReady,
        public readonly ?string $notes,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            candidateId: (int) $r['candidate_id'],
            preferredCountries: self::decodeList($r['preferred_countries'] ?? null),
            preferredJobTitles: self::decodeList($r['preferred_job_titles'] ?? null),
            minExpectedSalary: isset($r['min_expected_salary']) ? (float) $r['min_expected_salary'] : null,
            salaryCurrency: $r['salary_currency'] ?? null,
            willingToRelocate: (bool) ($r['willing_to_relocate'] ?? true),
            availableFrom: $r['available_from'] ?? null,
            passportReady: (bool) ($r['passport_ready'] ?? false),
            notes: $r['notes'] ?? null,
            updatedAt: (string) ($r['updated_at'] ?? ''),
        );
    }

    /** @return list<string> */
    private static function decodeList(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
