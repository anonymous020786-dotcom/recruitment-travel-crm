<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Support\Db;

/**
 * Read-only aggregates for the dashboard. One grouped query per widget — never a query per row —
 * each scoped to the viewer's branches. Named placeholders cannot repeat inside one statement, so
 * every widget is its own statement.
 */
final class DashboardRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Rows per calendar month (YYYY-MM) since the beginning of time, for a table's `$dateColumn`.
     * The dashboard takes the current month and the last few from this; the total is the sum.
     *
     * @return array<string,int>
     */
    public function monthly(string $table, string $dateColumn, string $branchColumn, BranchScope $scope, string $extraWhere = '1 = 1'): array
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($dateColumn);
        [$branchSql, $bind] = $scope->whereClause($branchColumn);
        $rows = $this->db->select(
            "SELECT DATE_FORMAT({$dateColumn}, '%Y-%m') AS m, COUNT(*) AS n FROM {$table} WHERE {$branchSql} AND {$extraWhere} GROUP BY m",
            $bind,
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['m']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return array<string,int> application status => count (every status that has rows) */
    public function applicationsByStatus(BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $rows = $this->db->select("SELECT status, COUNT(*) AS n FROM applications WHERE {$branchSql} GROUP BY status", $bind);

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /** Interviews still to happen between $from and $to (inclusive). */
    public function interviewsBetween(BranchScope $scope, string $from, string $to): int
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');

        return (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM interviews i JOIN applications a ON a.id = i.application_id
             WHERE i.status IN ('scheduled','confirmed') AND i.scheduled_date BETWEEN :from AND :to AND {$branchSql}",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /** @return array{approved:int,expiring:int} live approved visas, and those that expire within the window */
    public function visas(BranchScope $scope, string $today, string $until): array
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $r = $this->db->selectOne(
            "SELECT COUNT(*) AS approved, COALESCE(SUM(v.expiry_date BETWEEN :today AND :until), 0) AS expiring
             FROM visa_applications v JOIN candidates c ON c.id = v.candidate_id
             WHERE v.status = 'approved' AND {$branchSql}",
            ['today' => $today, 'until' => $until] + $bind,
        );

        return ['approved' => (int) ($r['approved'] ?? 0), 'expiring' => (int) ($r['expiring'] ?? 0)];
    }

    /** Fit medical certificates that expire within the window, for candidates with a live application. */
    public function medicalExpiring(BranchScope $scope, string $today, string $until): int
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');

        return (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM medical_records m JOIN candidates c ON c.id = m.candidate_id
             WHERE m.status = 'fit' AND m.expires_at BETWEEN :today AND :until AND {$branchSql}
               AND EXISTS (SELECT 1 FROM applications a WHERE a.candidate_id = c.id AND a.status NOT IN ('placed','rejected','cancelled'))",
            ['today' => $today, 'until' => $until] + $bind,
        );
    }

    /** Passports that expire within the window, for candidates with a live application. */
    public function passportsExpiring(BranchScope $scope, string $today, string $until): int
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');

        return (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM passports pp JOIN candidates c ON c.id = pp.candidate_id
             WHERE pp.expiry_date BETWEEN :today AND :until AND {$branchSql}
               AND EXISTS (SELECT 1 FROM applications a WHERE a.candidate_id = c.id AND a.status NOT IN ('placed','rejected','cancelled'))",
            ['today' => $today, 'until' => $until] + $bind,
        );
    }

    /**
     * Tour bookings per status, plus how many open ones travel within the window.
     *
     * @return array{by_status:array<string,int>,upcoming:int}
     */
    public function tours(BranchScope $scope, string $today, string $until): array
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $rows = $this->db->select(
            "SELECT status, COUNT(*) AS n,
                    COALESCE(SUM(status IN ('inquiry','quoted','confirmed') AND travel_date BETWEEN :today AND :until), 0) AS upcoming
             FROM tour_bookings WHERE {$branchSql} GROUP BY status",
            ['today' => $today, 'until' => $until] + $bind,
        );

        $by = [];
        $upcoming = 0;
        foreach ($rows as $r) {
            $by[(string) $r['status']] = (int) $r['n'];
            $upcoming += (int) $r['upcoming'];
        }

        return ['by_status' => $by, 'upcoming' => $upcoming];
    }

    /**
     * Money received since $since, per currency, net of nothing (refunds are shown separately).
     *
     * @return array<string,string> currency => amount (2 dp)
     */
    public function collectedSince(BranchScope $scope, string $since): array
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $rows = $this->db->select(
            "SELECT currency, SUM(amount) AS total FROM payments WHERE status = 'recorded' AND paid_at >= :since AND {$branchSql} GROUP BY currency ORDER BY currency",
            ['since' => $since] + $bind,
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['currency']] = number_format((float) $r['total'], 2, '.', '');
        }

        return $out;
    }

    public function refundsPending(BranchScope $scope): int
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');

        return (int) $this->db->selectValue("SELECT COUNT(*) FROM refunds WHERE status = 'pending' AND {$branchSql}", $bind);
    }

    /** Table / column names are interpolated (they cannot be bound), so only plain identifiers are allowed. */
    private function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $name) !== 1) {
            throw new \InvalidArgumentException("Not an identifier: {$name}");
        }
    }
}
