<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Authenticated user, hydrated from the `users` table joined to `roles`.
 * Immutable value object — never a live DB record.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $name,
        public readonly string $email,
        public readonly int $roleId,
        public readonly string $roleName,
        public readonly ?int $primaryBranchId,
        public readonly bool $isOrgWide,
        public readonly bool $isActive,
        public readonly ?string $lockedUntil,
        public readonly bool $mustChangePassword,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            publicId: (string) $row['public_id'],
            name: (string) $row['name'],
            email: (string) $row['email'],
            roleId: (int) $row['role_id'],
            roleName: (string) ($row['role_name'] ?? ''),
            primaryBranchId: isset($row['primary_branch_id']) ? (int) $row['primary_branch_id'] : null,
            isOrgWide: (bool) ($row['is_org_wide'] ?? false),
            isActive: (bool) ($row['is_active'] ?? false),
            lockedUntil: $row['locked_until'] ?? null,
            mustChangePassword: (bool) ($row['must_change_password'] ?? false),
        );
    }

    public function isLocked(): bool
    {
        return $this->lockedUntil !== null && strtotime($this->lockedUntil) > time();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleName === 'super_admin';
    }
}
