<?php

declare(strict_types=1);

namespace App\Models;

/** A sellable tour in the catalogue (`tour_packages`). Immutable read model; not branch-scoped. */
final class TourPackage
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $destination,
        public readonly ?int $durationDays,
        public readonly ?int $durationNights,
        public readonly ?string $startLocation,
        public readonly ?string $price,
        public readonly ?string $currency,
        public readonly ?string $hotelSummary,
        public readonly ?string $transportSummary,
        public readonly ?string $mealsSummary,
        public readonly ?string $inclusionsHtml,
        public readonly ?string $exclusionsHtml,
        public readonly ?string $termsHtml,
        public readonly string $status,
        public readonly bool $isPublic,
        public readonly int $itemCount,
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
            slug: (string) $r['slug'],
            name: (string) $r['name'],
            destination: (string) $r['destination'],
            durationDays: isset($r['duration_days']) ? (int) $r['duration_days'] : null,
            durationNights: isset($r['duration_nights']) ? (int) $r['duration_nights'] : null,
            startLocation: $r['start_location'] ?? null,
            price: isset($r['price']) ? (string) $r['price'] : null,
            currency: $r['currency'] ?? null,
            hotelSummary: $r['hotel_summary'] ?? null,
            transportSummary: $r['transport_summary'] ?? null,
            mealsSummary: $r['meals_summary'] ?? null,
            inclusionsHtml: $r['inclusions_html'] ?? null,
            exclusionsHtml: $r['exclusions_html'] ?? null,
            termsHtml: $r['terms_html'] ?? null,
            status: (string) $r['status'],
            isPublic: (bool) ($r['is_public'] ?? false),
            itemCount: (int) ($r['item_count'] ?? 0),
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
        );
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /** "5 days / 4 nights", "3 days", or "—". */
    public function durationLabel(): string
    {
        if ($this->durationDays === null && $this->durationNights === null) {
            return '—';
        }
        $parts = [];
        if ($this->durationDays !== null) {
            $parts[] = $this->durationDays . ' day' . ($this->durationDays === 1 ? '' : 's');
        }
        if ($this->durationNights !== null) {
            $parts[] = $this->durationNights . ' night' . ($this->durationNights === 1 ? '' : 's');
        }

        return implode(' / ', $parts);
    }

    public function priceLabel(): string
    {
        return $this->price === null ? '—' : trim(($this->currency ?? '') . ' ' . number_format((float) $this->price, 2));
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}
