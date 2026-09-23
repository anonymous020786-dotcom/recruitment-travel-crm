<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\TourBooking;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `tour_bookings`, branch-scoped on `tour_bookings.branch_id`, optimistic-locked on `record_version`. */
final class TourBookingRepository
{
    public const SORT = [
        'created_at'  => 'b.created_at',
        'travel_date' => 'b.travel_date',
        'customer'    => 'p.full_name',
        'status'      => 'b.status',
        'amount'      => 'b.total_amount',
    ];

    public const FILTER_KEYS = ['status', 'package', 'when'];

    /** Booking statuses in pipeline order (for the summary tiles). */
    public const STATUSES = ['inquiry', 'quoted', 'confirmed', 'travelling', 'completed', 'cancelled'];

    private const COLUMNS = 'b.id, b.public_id, b.booking_number, b.person_id, b.tour_package_id, b.branch_id, b.travel_date, b.return_date,
        b.adults, b.children, b.total_amount, b.currency, b.status, b.assigned_to, b.notes, b.record_version, b.created_at,
        p.full_name AS customer_name, p.primary_phone AS customer_phone, p.email AS customer_email,
        pk.public_id AS package_public_id, pk.name AS package_name, pk.destination AS package_destination,
        u.name AS assigned_to_name';

    private const JOINS = 'FROM tour_bookings b
        JOIN persons p ON p.id = b.person_id
        LEFT JOIN tour_packages pk ON pk.id = b.tour_package_id
        LEFT JOIN users u ON u.id = b.assigned_to';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?TourBooking
    {
        [$branchSql, $bind] = $scope->whereClause('b.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE b.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? TourBooking::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?TourBooking
    {
        [$branchSql, $bind] = $scope->whereClause('b.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE b.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? TourBooking::fromRow($row) : null;
    }

    /** @return list<TourBooking> a customer's bookings within the branches the viewer can see, newest first */
    public function forPerson(int $personId, BranchScope $scope, int $limit = 50): array
    {
        [$branchSql, $bind] = $scope->whereClause('b.branch_id');
        $limit = max(1, min($limit, 200));
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE b.person_id = :p AND {$branchSql} ORDER BY b.id DESC LIMIT {$limit}",
            ['p' => $personId] + $bind,
        );

        return array_map([TourBooking::class, 'fromRow'], $rows);
    }

    /** An open booking of the same customer for the same trip (package or none) and date — a double submit or a repeat inquiry. */
    public function hasOpenDuplicate(int $personId, ?int $packageId, ?string $travelDate): bool
    {
        return $this->db->exists(
            "SELECT 1 FROM tour_bookings WHERE person_id = :p AND tour_package_id <=> :pk AND travel_date <=> :d AND status IN ('inquiry','quoted','confirmed')",
            ['p' => $personId, 'pk' => $packageId, 'd' => $travelDate],
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('tour_bookings', $data);
    }

    /**
     * Optimistic write: applies $changes only if the booking is still at $expectedVersion.
     *
     * @param array<string,mixed> $changes
     * @return int rows affected (0 => stale, or outside scope)
     */
    public function updateVersioned(int $id, array $changes, int $expectedVersion, BranchScope $scope): int
    {
        [$branchSql, $branchBind] = $scope->whereClause('branch_id');

        $set = ['record_version = record_version + 1', 'updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id, 'ver' => $expectedVersion] + $branchBind;
        foreach ($changes as $col => $val) {
            $set[] = "`{$col}` = :c_{$col}";
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE tour_bookings SET ' . implode(', ', $set) . " WHERE id = :id AND record_version = :ver AND {$branchSql}",
            $bind,
        );
    }

    /** @return array<string,int> count per status (every status present) */
    public function statusCounts(BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $rows = $this->db->select("SELECT status, COUNT(*) AS n FROM tour_bookings WHERE {$branchSql} GROUP BY status", $bind);

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }

        return $counts;
    }

    /** @return Page<TourBooking> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM tour_bookings b JOIN persons p ON p.id = b.person_id LEFT JOIN tour_packages pk ON pk.id = b.tour_package_id WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'b.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, b.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([TourBooking::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('b.branch_id');
        $where = [$branchSql];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'b.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($package = $q->filter('package')) !== null && $package !== '') {
            $where[] = 'pk.public_id = :f_package';
            $bind['f_package'] = $package;
        }
        $when = $q->filter('when');
        if ($when === 'upcoming') {
            $where[] = "b.travel_date >= :f_today AND b.status IN ('inquiry','quoted','confirmed')";
            $bind['f_today'] = gmdate('Y-m-d');
        } elseif ($when === 'undated') {
            $where[] = "b.travel_date IS NULL AND b.status IN ('inquiry','quoted','confirmed')";
        }
        if ($q->hasSearch()) {
            $where[] = '(b.booking_number = :s_exact OR p.full_name LIKE :s_name OR p.primary_phone LIKE :s_phone OR pk.name LIKE :s_package)';
            $bind['s_exact'] = $q->search;
            $bind['s_name'] = $bind['s_phone'] = $bind['s_package'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
