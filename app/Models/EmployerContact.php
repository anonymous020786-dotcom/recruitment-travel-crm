<?php

declare(strict_types=1);

namespace App\Models;

/** One contact person at an employer (`employer_contacts`). Immutable read model. */
final class EmployerContact
{
    public function __construct(
        public readonly int $id,
        public readonly int $employerId,
        public readonly string $name,
        public readonly ?string $designation,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly bool $isPrimary,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            employerId: (int) $r['employer_id'],
            name: (string) $r['name'],
            designation: $r['designation'] ?? null,
            email: $r['email'] ?? null,
            phone: $r['phone'] ?? null,
            isPrimary: (bool) $r['is_primary'],
            createdAt: (string) $r['created_at'],
        );
    }
}
