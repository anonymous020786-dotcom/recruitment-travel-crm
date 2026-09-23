<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\DepartureRecord;
use App\Support\Db;

/** SQL for `departure_records` (one per application). Callers authorize through the application first. */
final class DepartureRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function forApplication(int $applicationId): ?DepartureRecord
    {
        $row = $this->db->selectOne('SELECT * FROM departure_records WHERE application_id = :a', ['a' => $applicationId]);

        return $row ? DepartureRecord::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('departure_records', $data);
    }

    /**
     * Guarded write used for arrival: only fills an arrival that has not been confirmed yet.
     *
     * @return int rows affected (0 => somebody else confirmed it first)
     */
    public function confirmArrival(int $id, string $arrivedAt, int $confirmedBy, ?string $notes): int
    {
        return $this->db->affectingStatement(
            'UPDATE departure_records SET arrived_at = :at, arrival_confirmed_by = :by, notes = COALESCE(:notes, notes) WHERE id = :id AND arrived_at IS NULL',
            ['at' => $arrivedAt, 'by' => $confirmedBy, 'notes' => $notes, 'id' => $id],
        );
    }

    public function markPlaced(int $id, string $at): int
    {
        return $this->db->affectingStatement(
            'UPDATE departure_records SET placement_confirmed_at = :at WHERE id = :id AND placement_confirmed_at IS NULL',
            ['at' => $at, 'id' => $id],
        );
    }
}
