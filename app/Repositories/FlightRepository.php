<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Sql;
use App\Auth\BranchScope;
use App\Models\FlightBooking;
use App\Support\Db;

/** SQL for `flight_bookings`, branch-scoped through the candidate. Writes are guarded on the status the caller last saw. */
final class FlightRepository
{
    private const COLUMNS = 'f.id, f.public_id, f.candidate_id, f.application_id, f.pnr, f.airline, f.flight_number,
        f.departure_airport, f.arrival_airport, f.departure_at, f.arrival_at, f.baggage_allowance, f.ticket_price, f.currency,
        f.status, f.notes, f.created_at,
        c.public_id AS candidate_public_id, c.candidate_number, c.branch_id, p.full_name AS candidate_name,
        a.public_id AS application_public_id, a.application_number,
        d.public_id AS doc_public_id, d.original_name AS doc_name';

    private const JOINS = 'FROM flight_bookings f
        JOIN candidates c ON c.id = f.candidate_id
        JOIN persons p ON p.id = c.person_id
        LEFT JOIN applications a ON a.id = f.application_id
        LEFT JOIN candidate_documents d ON d.id = f.ticket_document_id';

    public function __construct(private readonly Db $db)
    {
    }

    /** Points the flight at its ticket (a candidate document). */
    public function setTicket(int $id, int $documentId): void
    {
        $this->db->affectingStatement('UPDATE flight_bookings SET ticket_document_id = :d WHERE id = :id', ['d' => $documentId, 'id' => $id]);
    }

    public function findById(int $id, BranchScope $scope): ?FlightBooking
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE f.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? FlightBooking::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?FlightBooking
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE f.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? FlightBooking::fromRow($row) : null;
    }

    /** @return list<FlightBooking> newest first; the caller has already authorized the application */
    public function forApplication(int $applicationId): array
    {
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE f.application_id = :a ORDER BY f.id DESC', ['a' => $applicationId]);

        return array_map([FlightBooking::class, 'fromRow'], $rows);
    }

    /** Live (not cancelled / flown) flights of an application, optionally only ones with a ticket. */
    public function countLiveFor(int $applicationId, bool $ticketedOnly = false): int
    {
        $statuses = $ticketedOnly ? FlightBooking::TICKETED : FlightBooking::LIVE;
        [$in, $bind] = $this->in($statuses);

        return (int) $this->db->selectValue("SELECT COUNT(*) FROM flight_bookings WHERE application_id = :a AND status IN ({$in})", ['a' => $applicationId] + $bind);
    }

    /** The flight the candidate is expected to take: the newest ticketed one, else null. */
    public function currentTicketed(int $applicationId): ?FlightBooking
    {
        [$in, $bind] = $this->in(FlightBooking::TICKETED);
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE f.application_id = :a AND f.status IN ({$in}) ORDER BY f.id DESC LIMIT 1",
            ['a' => $applicationId] + $bind,
        );

        return $row ? FlightBooking::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('flight_bookings', $data);
    }

    /**
     * Guarded write: only touches a flight that is still in one of $fromStatuses.
     *
     * @param array<string,mixed> $set
     * @param list<string> $fromStatuses
     * @return int rows affected (0 => the flight changed under the caller)
     */
    public function updateFrom(int $id, array $set, array $fromStatuses): int
    {
        $cols = [];
        $bind = ['id' => $id];
        foreach ($set as $col => $val) {
            $cols[] = Sql::assign($col, 's_');
            $bind["s_{$col}"] = $val;
        }
        [$in, $inBind] = $this->in($fromStatuses, 'from');

        return $this->db->affectingStatement(
            'UPDATE flight_bookings SET ' . implode(', ', $cols) . " WHERE id = :id AND status IN ({$in})",
            $bind + $inBind,
        );
    }

    /**
     * @param list<string> $values
     * @return array{0:string,1:array<string,string>}
     */
    private function in(array $values, string $prefix = 'st'): array
    {
        $names = [];
        $bind = [];
        foreach (array_values($values) as $i => $v) {
            $names[] = ":{$prefix}{$i}";
            $bind["{$prefix}{$i}"] = $v;
        }

        return [implode(', ', $names), $bind];
    }
}
