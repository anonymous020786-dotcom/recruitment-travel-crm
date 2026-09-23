<?php

declare(strict_types=1);

namespace App\Models;

/** When a candidate left and when their arrival was confirmed (`departure_records`). Immutable read model. */
final class DepartureRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $candidateId,
        public readonly ?int $applicationId,
        public readonly ?int $flightBookingId,
        public readonly ?string $departedAt,
        public readonly ?string $arrivedAt,
        public readonly ?int $arrivalConfirmedBy,
        public readonly ?string $placementConfirmedAt,
        public readonly ?string $notes,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            candidateId: (int) $r['candidate_id'],
            applicationId: isset($r['application_id']) ? (int) $r['application_id'] : null,
            flightBookingId: isset($r['flight_booking_id']) ? (int) $r['flight_booking_id'] : null,
            departedAt: $r['departed_at'] ?? null,
            arrivedAt: $r['arrived_at'] ?? null,
            arrivalConfirmedBy: isset($r['arrival_confirmed_by']) ? (int) $r['arrival_confirmed_by'] : null,
            placementConfirmedAt: $r['placement_confirmed_at'] ?? null,
            notes: $r['notes'] ?? null,
        );
    }

    public function hasArrived(): bool
    {
        return $this->arrivedAt !== null;
    }
}
