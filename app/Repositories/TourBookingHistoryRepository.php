<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * `tour_booking_status_history` is append-only, like the application and visa
 * histories: this repository deliberately exposes no update or delete method, and
 * the only writer is TourBookingService inside the transition transaction.
 */
final class TourBookingHistoryRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function append(int $bookingId, ?string $from, string $to, ?string $reason, ?int $changedBy): void
    {
        $this->db->insertRow('tour_booking_status_history', [
            'tour_booking_id' => $bookingId, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'changed_by' => $changedBy,
        ]);
    }

    /** @return list<array{from:?string,to:string,reason:?string,by:?string,at:string}> newest first */
    public function forBooking(int $bookingId): array
    {
        $rows = $this->db->select(
            'SELECT h.from_status, h.to_status, h.reason, h.changed_at, u.name AS by_name
             FROM tour_booking_status_history h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.tour_booking_id = :id ORDER BY h.id DESC',
            ['id' => $bookingId],
        );

        return array_map(static fn (array $r): array => [
            'from' => $r['from_status'] ?? null, 'to' => (string) $r['to_status'],
            'reason' => $r['reason'] ?? null, 'by' => $r['by_name'] ?? null, 'at' => (string) $r['changed_at'],
        ], $rows);
    }
}
