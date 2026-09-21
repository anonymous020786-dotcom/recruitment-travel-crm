<?php

declare(strict_types=1);

namespace App\Models;

/** Configurable catalogue row (`document_types`). Immutable read model. */
final class DocumentType
{
    /** @param list<string> $allowedMime */
    public function __construct(
        public readonly int $id,
        public readonly string $keyName,
        public readonly string $label,
        public readonly string $category,
        public readonly bool $hasExpiry,
        public readonly bool $isRequiredDefault,
        public readonly array $allowedMime,
        public readonly int $maxSizeKb,
        public readonly int $sortOrder,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            keyName: (string) $r['key_name'],
            label: (string) $r['label'],
            category: (string) $r['category'],
            hasExpiry: (bool) $r['has_expiry'],
            isRequiredDefault: (bool) $r['is_required_default'],
            allowedMime: array_values(array_filter(array_map('trim', explode(',', (string) $r['allowed_mime'])))),
            maxSizeKb: (int) $r['max_size_kb'],
            sortOrder: (int) $r['sort_order'],
            isActive: (bool) $r['is_active'],
        );
    }
}
