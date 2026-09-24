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
     * Money received in the range, per month, currency and method (recorded payments only — reversed ones are not money).
     * Refunds are reported separately, never netted silently.
     */
    public function collections(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');

        yield from $this->db->cursor(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, currency, method, COUNT(*) AS payments, SUM(amount) AS total
             FROM payments WHERE status = 'recorded' AND DATE(paid_at) BETWEEN :from AND :to AND {$branchSql}
             GROUP BY month, currency, method ORDER BY month DESC, currency, method",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /** Every payment received in the range, with how much of it has been applied to invoices. */
    public function payments(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('pm.branch_id');

        yield from $this->db->cursor(
            "SELECT pm.paid_at, pm.payment_number, pm.receipt_number, p.full_name AS customer, pm.method, pm.reference, pm.currency, pm.amount, pm.status,
                    (SELECT COALESCE(SUM(a.amount), 0) FROM payment_allocations a WHERE a.payment_id = pm.id) AS allocated
             FROM payments pm JOIN persons p ON p.id = pm.person_id
             WHERE DATE(pm.paid_at) BETWEEN :from AND :to AND {$branchSql}
             ORDER BY pm.paid_at DESC, pm.id DESC",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /** Every invoice raised in the range, with what has been paid, refunded and is still owed. */
    public function invoices(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');

        yield from $this->db->cursor(
            "SELECT i.invoice_number, p.full_name AS customer, i.invoiceable_type AS kind, COALESCE(a.application_number, tb.booking_number) AS reference,
                    i.issued_on, i.due_on, i.currency, i.grand_total, i.amount_paid, i.amount_refunded,
                    GREATEST(i.grand_total - (i.amount_paid - i.amount_refunded), 0) AS outstanding, i.status
             FROM invoices i
             JOIN persons p ON p.id = i.person_id
             LEFT JOIN applications a ON i.invoiceable_type = 'application' AND a.id = i.invoiceable_id
             LEFT JOIN tour_bookings tb ON i.invoiceable_type = 'tour_booking' AND tb.id = i.invoiceable_id
             WHERE DATE(i.created_at) BETWEEN :from AND :to AND {$branchSql}
             ORDER BY i.created_at DESC, i.id DESC",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /** Every refund requested in the range. */
    public function refunds(BranchScope $scope, string $from, string $to): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('r.branch_id');

        yield from $this->db->cursor(
            "SELECT r.created_at, r.refund_number, p.full_name AS customer, pm.payment_number, i.invoice_number, r.currency, r.amount, r.method, r.status,
                    cu.name AS requested_by, au.name AS approved_by, r.reason
             FROM refunds r
             JOIN persons p ON p.id = r.person_id
             JOIN payments pm ON pm.id = r.payment_id
             LEFT JOIN invoices i ON i.id = r.invoice_id
             LEFT JOIN users cu ON cu.id = r.created_by
             LEFT JOIN users au ON au.id = r.approved_by
             WHERE DATE(r.created_at) BETWEEN :from AND :to AND {$branchSql}
             ORDER BY r.created_at DESC, r.id DESC",
            ['from' => $from, 'to' => $to] + $bind,
        );
    }

    /** Every invoice that is past due and still owes money, oldest first (a live snapshot, no date range). */
    public function overdueInvoices(BranchScope $scope, string $today): \Generator
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');
        $owed = 'GREATEST(i.grand_total - (i.amount_paid - i.amount_refunded), 0)';

        yield from $this->db->cursor(
            "SELECT i.invoice_number, p.full_name AS customer, p.primary_phone AS phone, i.due_on, DATEDIFF(:today, i.due_on) AS days_overdue,
                    i.currency, {$owed} AS outstanding, i.status
             FROM invoices i JOIN persons p ON p.id = i.person_id
             WHERE i.status IN ('issued','partially_paid') AND i.due_on < :today2 AND {$owed} > 0 AND {$branchSql}
             ORDER BY i.due_on, i.id",
            ['today' => $today, 'today2' => $today] + $bind,
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
