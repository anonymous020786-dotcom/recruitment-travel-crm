<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/**
 * The travel pipeline: applications between "visa approved" and "departed",
 * each with its current flight and departure record. Read-only; the writes live
 * in the flight / departure / placement repositories.
 */
final class TravelRepository
{
    /** Application statuses that belong on the travel desk, in pipeline order. */
    public const STAGES = ['visa_approved', 'ticket_pending', 'ticket_booked', 'departed'];

    public const SORT = [
        'updated'   => 'a.updated_at',
        'departure' => 'f.departure_at',
        'candidate' => 'p.full_name',
        'status'    => 'a.status',
    ];

    public const FILTER_KEYS = ['status'];

    private const STAGES_SQL = "('visa_approved','ticket_pending','ticket_booked','departed')";

    private const COLUMNS = 'a.id, a.public_id, a.application_number, a.status, a.branch_id, a.updated_at,
        c.public_id AS candidate_public_id, c.candidate_number, p.full_name AS candidate_name,
        j.title AS job_title, j.country, e.company_name AS employer_name,
        f.public_id AS flight_public_id, f.pnr, f.airline, f.flight_number, f.departure_airport, f.arrival_airport,
        f.departure_at, f.status AS flight_status,
        d.departed_at, d.arrived_at';

    private const JOINS = "FROM applications a
        JOIN candidates c ON c.id = a.candidate_id
        JOIN persons p ON p.id = c.person_id
        JOIN jobs j ON j.id = a.job_id
        JOIN employers e ON e.id = a.employer_id
        LEFT JOIN flight_bookings f ON f.id = (SELECT MAX(f2.id) FROM flight_bookings f2 WHERE f2.application_id = a.id AND f2.status <> 'cancelled')
        LEFT JOIN departure_records d ON d.application_id = a.id";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,int> count of applications per travel stage (every stage present) */
    public function stageCounts(BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS n FROM applications WHERE status IN ' . self::STAGES_SQL . " AND {$branchSql} GROUP BY status",
            $bind,
        );

        $counts = array_fill_keys(self::STAGES, 0);
        foreach ($rows as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }

        return $counts;
    }

    /** @return Page<array<string,mixed>> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN persons p ON p.id = c.person_id
             LEFT JOIN flight_bookings f ON f.id = (SELECT MAX(f2.id) FROM flight_bookings f2 WHERE f2.application_id = a.id AND f2.status <> \'cancelled\')
             WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'a.updated_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, a.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page($rows, $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $where = ['a.status IN ' . self::STAGES_SQL, $branchSql];

        $status = $q->filter('status');
        if ($status !== null && in_array($status, self::STAGES, true)) {
            $where[] = 'a.status = :f_status';
            $bind['f_status'] = $status;
        }
        if ($q->hasSearch()) {
            $where[] = '(c.candidate_number = :s_exact OR a.application_number = :s_app OR p.full_name LIKE :s_name OR f.pnr = :s_pnr)';
            $bind['s_exact'] = $bind['s_app'] = $bind['s_pnr'] = $q->search;
            $bind['s_name'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
