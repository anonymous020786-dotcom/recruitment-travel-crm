<?php

declare(strict_types=1);

namespace App\Models;

/** A flight booked for a candidate's application (`flight_bookings` joined to candidate/application). Immutable read model. */
final class FlightBooking
{
    /** Statuses that still describe a trip that may happen. */
    public const LIVE = ['planned', 'booked', 'issued', 'changed'];

    /** Statuses in which the ticket has actually been obtained. */
    public const TICKETED = ['booked', 'issued'];

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $candidateId,
        public readonly string $candidatePublicId,
        public readonly string $candidateName,
        public readonly string $candidateNumber,
        public readonly int $branchId,
        public readonly ?int $applicationId,
        public readonly ?string $applicationPublicId,
        public readonly ?string $applicationNumber,
        public readonly ?string $pnr,
        public readonly ?string $airline,
        public readonly ?string $flightNumber,
        public readonly ?string $departureAirport,
        public readonly ?string $arrivalAirport,
        public readonly ?string $departureAt,
        public readonly ?string $arrivalAt,
        public readonly ?string $baggageAllowance,
        public readonly ?string $ticketPrice,
        public readonly ?string $currency,
        public readonly string $status,
        public readonly ?string $notes,
        public readonly string $createdAt,
        public readonly ?string $ticketDocumentPublicId = null,
        public readonly ?string $ticketDocumentName = null,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            candidateId: (int) $r['candidate_id'],
            candidatePublicId: (string) ($r['candidate_public_id'] ?? ''),
            candidateName: (string) ($r['candidate_name'] ?? ''),
            candidateNumber: (string) ($r['candidate_number'] ?? ''),
            branchId: (int) ($r['branch_id'] ?? 0),
            applicationId: isset($r['application_id']) ? (int) $r['application_id'] : null,
            applicationPublicId: $r['application_public_id'] ?? null,
            applicationNumber: $r['application_number'] ?? null,
            pnr: $r['pnr'] ?? null,
            airline: $r['airline'] ?? null,
            flightNumber: $r['flight_number'] ?? null,
            departureAirport: $r['departure_airport'] ?? null,
            arrivalAirport: $r['arrival_airport'] ?? null,
            departureAt: $r['departure_at'] ?? null,
            arrivalAt: $r['arrival_at'] ?? null,
            baggageAllowance: $r['baggage_allowance'] ?? null,
            ticketPrice: isset($r['ticket_price']) ? (string) $r['ticket_price'] : null,
            currency: $r['currency'] ?? null,
            status: (string) $r['status'],
            notes: $r['notes'] ?? null,
            createdAt: (string) $r['created_at'],
            ticketDocumentPublicId: $r['doc_public_id'] ?? null,
            ticketDocumentName: $r['doc_name'] ?? null,
        );
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isTicketed(): bool
    {
        return in_array($this->status, self::TICKETED, true);
    }

    public function route(): string
    {
        return ($this->departureAirport ?? '—') . ' → ' . ($this->arrivalAirport ?? '—');
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}
