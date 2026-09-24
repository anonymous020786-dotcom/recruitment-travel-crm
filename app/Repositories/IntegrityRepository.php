<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * Read-only consistency probes over the ledger and pipelines. Each method returns the offending rows
 * (capped) as `['ref' => human reference, 'detail' => what is wrong]`; an empty list means the invariant holds.
 * They exist because foreign keys and CHECK constraints cannot express these rules (sums over child rows,
 * polymorphic links, derived statuses) — the services enforce them on write, this proves they held.
 */
final class IntegrityRepository
{
    public const LIMIT = 50;

    public function __construct(private readonly Db $db)
    {
    }

    /** An invoice's `amount_paid` must equal what its recorded payments have applied to it. */
    public function invoicePaidMismatch(): array
    {
        return $this->rows(
            "SELECT i.invoice_number AS ref, CONCAT('amount_paid ', i.amount_paid, ' but recorded allocations total ', COALESCE(x.s, 0)) AS detail
             FROM invoices i
             LEFT JOIN (SELECT a.invoice_id, SUM(a.amount) AS s FROM payment_allocations a JOIN payments p ON p.id = a.payment_id WHERE p.status = 'recorded' GROUP BY a.invoice_id) x ON x.invoice_id = i.id
             WHERE i.amount_paid <> COALESCE(x.s, 0)",
        );
    }

    /** `amount_refunded` must equal the refunds actually paid against the invoice. */
    public function invoiceRefundedMismatch(): array
    {
        return $this->rows(
            "SELECT i.invoice_number AS ref, CONCAT('amount_refunded ', i.amount_refunded, ' but paid refunds total ', COALESCE(x.s, 0)) AS detail
             FROM invoices i
             LEFT JOIN (SELECT invoice_id, SUM(amount) AS s FROM refunds WHERE status = 'paid' AND invoice_id IS NOT NULL GROUP BY invoice_id) x ON x.invoice_id = i.id
             WHERE i.amount_refunded <> COALESCE(x.s, 0)",
        );
    }

    /** A non-draft, non-void invoice's status must be the one its money justifies. */
    public function invoiceStatusMismatch(): array
    {
        return $this->rows(
            "SELECT invoice_number AS ref, CONCAT('status ', status, ' but its money says ', expected) AS detail FROM (
                SELECT i.invoice_number, i.status,
                       CASE WHEN i.amount_paid - i.amount_refunded <= 0 THEN 'issued'
                            WHEN i.amount_paid - i.amount_refunded >= i.grand_total THEN 'paid'
                            ELSE 'partially_paid' END AS expected
                FROM invoices i WHERE i.status IN ('issued','partially_paid','paid')) t
             WHERE status <> expected",
        );
    }

    /** grand = subtotal − discount + tax, and subtotal = the sum of the lines. */
    public function invoiceTotalsMismatch(): array
    {
        return $this->rows(
            "SELECT i.invoice_number AS ref,
                    CONCAT('grand ', i.grand_total, ' vs ', i.subtotal - i.discount_total + i.tax_total, ' (subtotal ', i.subtotal, ', lines ', COALESCE(l.s, 0), ')') AS detail
             FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(line_total) AS s FROM invoice_lines GROUP BY invoice_id) l ON l.invoice_id = i.id
             WHERE i.grand_total <> i.subtotal - i.discount_total + i.tax_total OR i.subtotal <> COALESCE(l.s, 0)",
        );
    }

    /** A payment cannot have applied more than it was worth. */
    public function paymentOverAllocated(): array
    {
        return $this->rows(
            "SELECT p.payment_number AS ref, CONCAT('amount ', p.amount, ' but allocated ', x.s) AS detail
             FROM payments p JOIN (SELECT payment_id, SUM(amount) AS s FROM payment_allocations GROUP BY payment_id) x ON x.payment_id = p.id
             WHERE x.s > p.amount",
        );
    }

    /** Refunds that are pending, approved or paid cannot together exceed the payment. */
    public function refundsExceedPayment(): array
    {
        return $this->rows(
            "SELECT p.payment_number AS ref, CONCAT('amount ', p.amount, ' but live refunds total ', x.s) AS detail
             FROM payments p JOIN (SELECT payment_id, SUM(amount) AS s FROM refunds WHERE status IN ('pending','approved','paid') GROUP BY payment_id) x ON x.payment_id = p.id
             WHERE x.s > p.amount",
        );
    }

    /** Every payment has exactly the receipt its number promises. */
    public function paymentReceiptMismatch(): array
    {
        return $this->rows(
            "SELECT p.payment_number AS ref, IF(r.id IS NULL, 'no receipt was issued', CONCAT('receipt ', r.receipt_number, ' but the payment says ', p.receipt_number)) AS detail
             FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id
             WHERE r.id IS NULL OR r.receipt_number <> p.receipt_number",
        );
    }

    /** Invoices point at an application / tour booking through a polymorphic link no foreign key can guard. */
    public function orphanInvoiceables(): array
    {
        return $this->rows(
            "SELECT i.invoice_number AS ref, CONCAT(i.invoiceable_type, ' #', i.invoiceable_id, ' does not exist') AS detail
             FROM invoices i
             LEFT JOIN applications a ON i.invoiceable_type = 'application' AND a.id = i.invoiceable_id
             LEFT JOIN tour_bookings tb ON i.invoiceable_type = 'tour_booking' AND tb.id = i.invoiceable_id
             WHERE (i.invoiceable_type = 'application' AND a.id IS NULL) OR (i.invoiceable_type = 'tour_booking' AND tb.id IS NULL)",
        );
    }

    /** An application's status must be the last thing its append-only history says. */
    public function applicationHistoryMismatch(): array
    {
        return $this->rows(
            "SELECT a.application_number AS ref, CONCAT('status ', a.status, ' but history ends at ', COALESCE(h.to_status, '(no history)')) AS detail
             FROM applications a
             LEFT JOIN application_status_history h ON h.id = (SELECT MAX(h2.id) FROM application_status_history h2 WHERE h2.application_id = a.id)
             WHERE h.id IS NULL OR h.to_status <> a.status",
        );
    }

    /**
     * Document-number counters must be ahead of every number already issued (or the next document would
     * collide). Checks the current and previous year.
     */
    public function sequencesBehind(): array
    {
        $out = [];
        $docs = [['invoice', 'invoices', 'invoice_number', 'INV'], ['payment', 'payments', 'payment_number', 'PAY'], ['receipt', 'payments', 'receipt_number', 'RCT'], ['refund', 'refunds', 'refund_number', 'RF']];

        foreach ([(int) gmdate('Y'), (int) gmdate('Y') - 1] as $year) {
            foreach ($docs as [$scope, $table, $column, $prefix]) {
                $max = (int) $this->db->selectValue(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX({$column}, '-', -1) AS UNSIGNED)), 0) FROM {$table} WHERE {$column} LIKE :p",
                    ['p' => "{$prefix}-{$year}-%"],
                );
                if ($max === 0) {
                    continue;
                }
                $next = $this->db->selectValue('SELECT next_value FROM number_sequences WHERE scope = :s', ['s' => "{$scope}:{$year}"]);
                if ($next === null || (int) $next <= $max) {
                    $out[] = ['ref' => "{$scope}:{$year}", 'detail' => 'counter is ' . ($next ?? 'missing') . " but {$prefix}-{$year}-" . str_pad((string) $max, 6, '0', STR_PAD_LEFT) . ' has been issued'];
                }
            }
        }

        return $out;
    }

    /** @return list<array{ref:string,detail:string}> */
    private function rows(string $sql): array
    {
        return array_map(
            static fn (array $r): array => ['ref' => (string) $r['ref'], 'detail' => (string) $r['detail']],
            $this->db->select($sql . ' LIMIT ' . self::LIMIT),
        );
    }
}
