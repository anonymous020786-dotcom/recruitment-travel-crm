<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One passport record on a candidate profile. A candidate may hold more than
 * one (renewal in progress, dual nationality); exactly one is flagged
 * `is_primary` — enforced by the service, not the schema. Immutable read
 * model, hydrated from `passports`.
 */
final class Passport
{
    public function __construct(
        public readonly int $id,
        public readonly int $candidateId,
        public readonly string $passportNumber,
        public readonly ?string $issueDate,
        public readonly ?string $expiryDate,
        public readonly ?string $placeOfIssue,
        public readonly ?string $nationality,
        public readonly bool $isPrimary,
        public readonly string $heldBy,
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
            passportNumber: (string) $r['passport_number'],
            issueDate: $r['issue_date'] ?? null,
            expiryDate: $r['expiry_date'] ?? null,
            placeOfIssue: $r['place_of_issue'] ?? null,
            nationality: $r['nationality'] ?? null,
            isPrimary: (bool) ($r['is_primary'] ?? false),
            heldBy: (string) ($r['held_by'] ?? 'candidate'),
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
        );
    }

    /** Days from today to expiry; negative once expired. Null if no expiry date on file. */
    public function daysUntilExpiry(?string $today = null): ?int
    {
        if ($this->expiryDate === null) {
            return null;
        }
        $today ??= gmdate('Y-m-d');

        return (int) ((strtotime($this->expiryDate) - strtotime($today)) / 86400);
    }

    public function isExpired(?string $today = null): bool
    {
        $days = $this->daysUntilExpiry($today);

        return $days !== null && $days < 0;
    }

    /** Expiring within the window (default 6 months) but not already expired. */
    public function isExpiringSoon(int $withinDays = 180, ?string $today = null): bool
    {
        $days = $this->daysUntilExpiry($today);

        return $days !== null && $days >= 0 && $days <= $withinDays;
    }

    public function heldByLabel(): string
    {
        return ucfirst($this->heldBy);
    }
}
