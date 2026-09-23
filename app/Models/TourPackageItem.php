<?php

declare(strict_types=1);

namespace App\Models;

/** One line of a package's itinerary (`tour_package_items`). Immutable read model. */
final class TourPackageItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $packageId,
        public readonly ?int $dayNo,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $sortOrder,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            packageId: (int) $r['tour_package_id'],
            dayNo: isset($r['day_no']) ? (int) $r['day_no'] : null,
            title: (string) $r['title'],
            description: $r['description'] ?? null,
            sortOrder: (int) $r['sort_order'],
        );
    }
}
