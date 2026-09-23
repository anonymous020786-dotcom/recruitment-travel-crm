<?php

declare(strict_types=1);

namespace App\Models;

/** A candidate's travel readiness snapshot plus preferences (`travel_profiles`, one per candidate). Immutable read model. */
final class TravelProfile
{
    public const READINESS = ['not_ready', 'planning', 'ticket_pending', 'ticket_booked', 'departed', 'arrived'];

    public function __construct(
        public readonly int $id,
        public readonly int $candidateId,
        public readonly ?int $applicationId,
        public readonly string $readiness,
        public readonly ?string $preferredDepartureCity,
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
            readiness: (string) $r['readiness'],
            preferredDepartureCity: $r['preferred_departure_city'] ?? null,
            notes: $r['notes'] ?? null,
        );
    }

    public function readinessLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->readiness));
    }
}
