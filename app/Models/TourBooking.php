<?php

declare(strict_types=1);

namespace App\Models;

/** A customer's booking of a tour (`tour_bookings` joined to person / package / assignee). Immutable read model. */
final class TourBooking
{
    /** Statuses in which the booking's details can still be edited. */
    public const OPEN = ['inquiry', 'quoted', 'confirmed'];

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $bookingNumber,
        public readonly int $personId,
        public readonly string $customerName,
        public readonly ?string $customerPhone,
        public readonly ?string $customerEmail,
        public readonly ?int $packageId,
        public readonly ?string $packagePublicId,
        public readonly ?string $packageName,
        public readonly ?string $packageDestination,
        public readonly int $branchId,
        public readonly ?string $travelDate,
        public readonly ?string $returnDate,
        public readonly int $adults,
        public readonly int $children,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?int $assignedTo,
        public readonly ?string $assignedToName,
        public readonly ?string $notes,
        public readonly int $recordVersion,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            bookingNumber: (string) $r['booking_number'],
            personId: (int) $r['person_id'],
            customerName: (string) ($r['customer_name'] ?? ''),
            customerPhone: $r['customer_phone'] ?? null,
            customerEmail: $r['customer_email'] ?? null,
            packageId: isset($r['tour_package_id']) ? (int) $r['tour_package_id'] : null,
            packagePublicId: $r['package_public_id'] ?? null,
            packageName: $r['package_name'] ?? null,
            packageDestination: $r['package_destination'] ?? null,
            branchId: (int) $r['branch_id'],
            travelDate: $r['travel_date'] ?? null,
            returnDate: $r['return_date'] ?? null,
            adults: (int) $r['adults'],
            children: (int) $r['children'],
            totalAmount: (string) $r['total_amount'],
            currency: (string) $r['currency'],
            status: (string) $r['status'],
            assignedTo: isset($r['assigned_to']) ? (int) $r['assigned_to'] : null,
            assignedToName: $r['assigned_to_name'] ?? null,
            notes: $r['notes'] ?? null,
            recordVersion: (int) $r['record_version'],
            createdAt: (string) $r['created_at'],
        );
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function travellers(): int
    {
        return $this->adults + $this->children;
    }

    public function travellersLabel(): string
    {
        $s = $this->adults . ' adult' . ($this->adults === 1 ? '' : 's');

        return $this->children > 0 ? $s . ', ' . $this->children . ' child' . ($this->children === 1 ? '' : 'ren') : $s;
    }

    public function amountLabel(): string
    {
        return $this->currency . ' ' . number_format((float) $this->totalAmount, 2);
    }

    /** What the trip is: the package name, or "Custom trip". */
    public function tripLabel(): string
    {
        return $this->packageName ?? 'Custom trip';
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}
