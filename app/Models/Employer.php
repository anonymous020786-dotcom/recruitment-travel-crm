<?php

declare(strict_types=1);

namespace App\Models;

/**
 * An employer / recruiting client (`employers`). Immutable read model.
 * `branchId` is nullable — a head-office-managed account not tied to one
 * branch is invisible to a branch-scoped user by design (BranchScope::contains
 * treats a null record branch as out of scope for anyone but an org-wide
 * user), the same rule every other nullable-branch record in this app follows.
 */
final class Employer
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $employerNumber,
        public readonly string $companyName,
        public readonly string $country,
        public readonly ?string $city,
        public readonly ?string $address,
        public readonly ?string $industry,
        public readonly ?string $website,
        public readonly ?string $licenseNumber,
        public readonly ?string $licenseExpiry,
        public readonly string $status,
        public readonly ?int $branchId,
        public readonly ?int $accountOwner,
        public readonly ?string $accountOwnerName,
        public readonly ?string $notes,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            employerNumber: (string) $r['employer_number'],
            companyName: (string) $r['company_name'],
            country: (string) $r['country'],
            city: $r['city'] ?? null,
            address: $r['address'] ?? null,
            industry: $r['industry'] ?? null,
            website: $r['website'] ?? null,
            licenseNumber: $r['license_number'] ?? null,
            licenseExpiry: $r['license_expiry'] ?? null,
            status: (string) $r['status'],
            branchId: isset($r['branch_id']) ? (int) $r['branch_id'] : null,
            accountOwner: isset($r['account_owner']) ? (int) $r['account_owner'] : null,
            accountOwnerName: $r['account_owner_name'] ?? null,
            notes: $r['notes'] ?? null,
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
        );
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
