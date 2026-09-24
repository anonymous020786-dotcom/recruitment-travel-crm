<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Support\Db;

/**
 * Read-only reporting queries. Each method is one statement, branch-scoped, and returns a Generator of
 * associative rows so a large report is streamed row by row (memory stays flat) rather than built in memory.
 * `$from` / `$to` are inclusive YYYY-MM-DD dates and are always bound, never interpolated.
 */
final class ReportRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Leads created in the range, per source: how many, and how many were converted. */
    public function leadSources(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('l.branch_id');

        yield from $this->db->cursor(
            "SELECT COALESCE(src.name, '(no source)') AS source, COUNT(*) AS leads,
                    COALESCE(SUM(st.key_name = 'converted'), 0) AS converted
             FROM leads l
             JOIN lead_statuses st ON st.id = l.status_id
             LEFT JOIN lead_sources src ON src.id = l.source_id
             WHERE l.deleted_at IS NULL AND DATE(l.created_at) BETWEEN :from AND :to AND {$branchSql}
             GROUP BY src.id, src.name ORDER BY leads DESC, source",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /** Applications made in the range, per employer, split by where they ended up. */
    public function applicationsByEmployer(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');

        yield from $this->db->cursor(
            "SELECT e.company_name AS employer, COUNT(*) AS applications,
                    COALESCE(SUM(a.status NOT IN ('placed','rejected','cancelled')), 0) AS live,
                    COALESCE(SUM(a.status = 'placed'), 0) AS placed,
                    COALESCE(SUM(a.status = 'rejected'), 0) AS rejected,
                    COALESCE(SUM(a.status = 'cancelled'), 0) AS cancelled
             FROM applications a JOIN employers e ON e.id = a.employer_id
             WHERE DATE(a.applied_at) BETWEEN :from AND :to AND {$branchSql}
             GROUP BY e.id, e.company_name ORDER BY applications DESC, employer",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /** Placements made in the range, one row each. */
    public function placements(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('pl.branch_id');

        yield from $this->db->cursor(
            "SELECT pl.placed_on, p.full_name AS candidate, c.candidate_number, e.company_name AS employer, j.title AS job, j.country,
                    pl.monthly_salary, pl.currency, pl.contract_end, pl.status
             FROM placements pl
             JOIN candidates c ON c.id = pl.candidate_id
             JOIN persons p ON p.id = c.person_id
             JOIN employers e ON e.id = pl.employer_id
             JOIN jobs j ON j.id = pl.job_id
             WHERE pl.placed_on BETWEEN :from AND :to AND {$branchSql}
             ORDER BY pl.placed_on DESC, pl.id DESC",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /**
     * Visas, medical certificates and passports that expire between today and `$until`, soonest first.
     * Only the requested kinds are queried (the caller decides from the viewer's permissions).
     *
     * @param list<string> $kinds any of visa, medical, passport
     */
    public function expiring(BranchScope $scope, string $today, string $until, array $kinds): \Generator
    {
        $parts = [];
        $bind = [];
        $n = 0;
        foreach (array_values(array_intersect(['visa', 'medical', 'passport'], $kinds)) as $kind) {
            [$branchSql, $b] = $scope->whereClause('c.branch_id');
            // Every part needs its own placeholder names.
            $renamed = [];
            foreach ($b as $k => $v) {
                $renamed["{$k}_{$n}"] = $v;
            }
            $branchSql = (string) preg_replace('/:(bs\d+)\b/', ':$1_' . $n, $branchSql);
            $bind += $renamed + ["today_{$n}" => $today, "until_{$n}" => $until];
            $parts[] = match ($kind) {
                'visa' => "SELECT 'Visa' AS kind, p.full_name AS candidate, c.candidate_number, CONCAT(v.country, COALESCE(CONCAT(' ', v.visa_number), '')) AS reference, v.expiry_date AS expires
                           FROM visa_applications v JOIN candidates c ON c.id = v.candidate_id JOIN persons p ON p.id = c.person_id
                           WHERE v.status = 'approved' AND v.expiry_date BETWEEN :today_{$n} AND :until_{$n} AND {$branchSql}",
                'medical' => "SELECT 'Medical certificate' AS kind, p.full_name AS candidate, c.candidate_number, COALESCE(m.medical_center, '') AS reference, m.expires_at AS expires
                              FROM medical_records m JOIN candidates c ON c.id = m.candidate_id JOIN persons p ON p.id = c.person_id
                              WHERE m.status = 'fit' AND m.expires_at BETWEEN :today_{$n} AND :until_{$n} AND {$branchSql}",
                default => "SELECT 'Passport' AS kind, p.full_name AS candidate, c.candidate_number, pp.passport_number AS reference, pp.expiry_date AS expires
                            FROM passports pp JOIN candidates c ON c.id = pp.candidate_id JOIN persons p ON p.id = c.person_id
                            WHERE pp.expiry_date BETWEEN :today_{$n} AND :until_{$n} AND {$branchSql}",
            };
            $n++;
        }
        if ($parts === []) {
            return;
        }

        yield from $this->db->cursor('(' . implode(') UNION ALL (', $parts) . ') ORDER BY expires, candidate', $bind);
    }

    /** Flights departing in the range, one row each. */
    public function flights(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');

        yield from $this->db->cursor(
            "SELECT f.departure_at, p.full_name AS candidate, c.candidate_number, a.application_number,
                    CONCAT(COALESCE(f.departure_airport, '?'), ' → ', COALESCE(f.arrival_airport, '?')) AS route,
                    f.airline, f.flight_number, f.pnr, f.status
             FROM flight_bookings f
             JOIN candidates c ON c.id = f.candidate_id
             JOIN persons p ON p.id = c.person_id
             LEFT JOIN applications a ON a.id = f.application_id
             WHERE DATE(f.departure_at) BETWEEN :from AND :to AND {$branchSql}
             ORDER BY f.departure_at DESC, f.id DESC",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /**
     * Tour bookings created in the range, per package (and currency, since money is never mixed):
     * counts by outcome and the value of the bookings that are actually going ahead.
     */
    public function tourPackages(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('b.branch_id');

        yield from $this->db->cursor(
            "SELECT COALESCE(pk.name, '(custom trip)') AS package, b.currency, COUNT(*) AS bookings,
                    COALESCE(SUM(b.status IN ('inquiry','quoted')), 0) AS open_pipeline,
                    COALESCE(SUM(b.status IN ('confirmed','travelling')), 0) AS confirmed,
                    COALESCE(SUM(b.status = 'completed'), 0) AS completed,
                    COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(CASE WHEN b.status IN ('confirmed','travelling','completed') THEN b.total_amount ELSE 0 END), 0) AS revenue
             FROM tour_bookings b LEFT JOIN tour_packages pk ON pk.id = b.tour_package_id
             WHERE DATE(b.created_at) BETWEEN :from AND :to AND {$branchSql}
             GROUP BY pk.id, pk.name, b.currency ORDER BY revenue DESC, package",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }
}
