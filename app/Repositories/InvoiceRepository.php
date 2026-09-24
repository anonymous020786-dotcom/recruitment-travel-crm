<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Invoice;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `invoices`, branch-scoped on `invoices.branch_id`, optimistic-locked on `record_version`. */
final class InvoiceRepository
{
    public const SORT = [
        'created_at' => 'i.created_at',
        'number'     => 'i.invoice_number',
        'customer'   => 'p.full_name',
        'due_on'     => 'i.due_on',
        'total'      => 'i.grand_total',
        'status'     => 'i.status',
    ];

    public const FILTER_KEYS = ['status', 'type', 'due'];

    public const STATUSES = ['draft', 'issued', 'partially_paid', 'paid', 'void'];

    private const COLUMNS = 'i.id, i.public_id, i.invoice_number, i.person_id, i.branch_id, i.invoiceable_type, i.invoiceable_id,
        i.currency, i.subtotal, i.discount_total, i.tax_total, i.grand_total, i.amount_paid, i.amount_refunded, i.status,
        i.issued_on, i.due_on, i.notes, i.record_version, i.created_at,
        p.full_name AS customer_name, p.primary_phone AS customer_phone,
        COALESCE(a.application_number, tb.booking_number) AS reference_number,
        COALESCE(a.public_id, tb.public_id) AS reference_public_id';

    private const JOINS = "FROM invoices i
        JOIN persons p ON p.id = i.person_id
        LEFT JOIN applications a ON i.invoiceable_type = 'application' AND a.id = i.invoiceable_id
        LEFT JOIN tour_bookings tb ON i.invoiceable_type = 'tour_booking' AND tb.id = i.invoiceable_id";

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Invoice
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE i.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? Invoice::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Invoice
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE i.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? Invoice::fromRow($row) : null;
    }

    public function findByNumber(string $invoiceNumber, BranchScope $scope): ?Invoice
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE i.invoice_number = :n AND {$branchSql}", ['n' => $invoiceNumber] + $bind);

        return $row ? Invoice::fromRow($row) : null;
    }

    /** @return list<Invoice> invoices raised for one application / tour booking, newest first */
    public function forInvoiceable(string $type, int $id, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE i.invoiceable_type = :t AND i.invoiceable_id = :id AND {$branchSql} ORDER BY i.id DESC",
            ['t' => $type, 'id' => $id] + $bind,
        );

        return array_map([Invoice::class, 'fromRow'], $rows);
    }

    /** @return list<Invoice> a person's invoices within the visible branches, newest first */
    public function forPerson(int $personId, BranchScope $scope, int $limit = 50): array
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');
        $limit = max(1, min($limit, 200));
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE i.person_id = :p AND {$branchSql} ORDER BY i.id DESC LIMIT {$limit}",
            ['p' => $personId] + $bind,
        );

        return array_map([Invoice::class, 'fromRow'], $rows);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('invoices', $data);
    }

    /**
     * Optimistic write: applies $changes only if the invoice is still at $expectedVersion.
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
            'UPDATE invoices SET ' . implode(', ', $set) . " WHERE id = :id AND record_version = :ver AND {$branchSql}",
            $bind,
        );
    }

    /**
     * Row-locks an invoice for the rest of the transaction and returns its money state, so two payments
     * arriving together are applied one after the other against the true outstanding amount.
     * The caller must be inside a transaction and must already have authorised access to the invoice.
     *
     * @return array{status:string,currency:string,person_id:int,grand_total:string,amount_paid:string,amount_refunded:string}|null
     */
    public function lockForPayment(int $id): ?array
    {
        $r = $this->db->selectOne('SELECT status, currency, person_id, grand_total, amount_paid, amount_refunded FROM invoices WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $r === null ? null : [
            'status' => (string) $r['status'], 'currency' => (string) $r['currency'], 'person_id' => (int) $r['person_id'],
            'grand_total' => (string) $r['grand_total'], 'amount_paid' => (string) $r['amount_paid'], 'amount_refunded' => (string) $r['amount_refunded'],
        ];
    }

    /** Writes the result of a payment (or its reversal) onto a locked invoice and bumps its version. */
    public function setPaymentState(int $id, string $amountPaid, string $status): void
    {
        $this->db->affectingStatement(
            'UPDATE invoices SET amount_paid = :paid, status = :st, record_version = record_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['paid' => $amountPaid, 'st' => $status, 'id' => $id],
        );
    }

    /**
     * Receivables ageing per currency: what is owed, split by how long past its due date it is.
     * Only issued / partially-paid invoices with something outstanding count.
     *
     * @return list<array{currency:string,invoices:int,current:string,d1_30:string,d31_60:string,d61_90:string,d90_plus:string,total:string}>
     */
    public function aging(BranchScope $scope, ?string $today = null): array
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $owed = 'GREATEST(grand_total - (amount_paid - amount_refunded), 0)';
        $rows = $this->db->select(
            "SELECT currency, COUNT(*) AS invoices,
                    SUM(CASE WHEN due_on IS NULL OR due_on >= :t0 THEN {$owed} ELSE 0 END) AS current_amt,
                    SUM(CASE WHEN due_on < :t1 AND DATEDIFF(:t2, due_on) <= 30 THEN {$owed} ELSE 0 END) AS d1_30,
                    SUM(CASE WHEN due_on < :t3 AND DATEDIFF(:t4, due_on) BETWEEN 31 AND 60 THEN {$owed} ELSE 0 END) AS d31_60,
                    SUM(CASE WHEN due_on < :t5 AND DATEDIFF(:t6, due_on) BETWEEN 61 AND 90 THEN {$owed} ELSE 0 END) AS d61_90,
                    SUM(CASE WHEN due_on < :t7 AND DATEDIFF(:t8, due_on) > 90 THEN {$owed} ELSE 0 END) AS d90_plus,
                    SUM({$owed}) AS total
             FROM invoices
             WHERE status IN ('issued','partially_paid') AND {$owed} > 0 AND {$branchSql}
             GROUP BY currency ORDER BY currency",
            array_fill_keys(['t0', 't1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'], $today ?? gmdate('Y-m-d')) + $bind,
        );

        return array_map(static fn (array $r): array => [
            'currency' => (string) $r['currency'], 'invoices' => (int) $r['invoices'], 'current' => (string) $r['current_amt'],
            'd1_30' => (string) $r['d1_30'], 'd31_60' => (string) $r['d31_60'], 'd61_90' => (string) $r['d61_90'],
            'd90_plus' => (string) $r['d90_plus'], 'total' => (string) $r['total'],
        ], $rows);
    }

    /**
     * The customers who owe the most, per currency, biggest first.
     *
     * @return list<array{person_id:int,customer_name:string,currency:string,invoices:int,outstanding:string,overdue:string,oldest_due:?string}>
     */
    public function topDebtors(BranchScope $scope, int $limit = 15, ?string $today = null): array
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');
        $limit = max(1, min($limit, 100));
        $owed = 'GREATEST(i.grand_total - (i.amount_paid - i.amount_refunded), 0)';
        $rows = $this->db->select(
            "SELECT i.person_id, p.full_name AS customer_name, i.currency, COUNT(*) AS invoices, SUM({$owed}) AS outstanding,
                    SUM(CASE WHEN i.due_on < :today THEN {$owed} ELSE 0 END) AS overdue, MIN(i.due_on) AS oldest_due
             FROM invoices i JOIN persons p ON p.id = i.person_id
             WHERE i.status IN ('issued','partially_paid') AND {$owed} > 0 AND {$branchSql}
             GROUP BY i.person_id, p.full_name, i.currency ORDER BY outstanding DESC LIMIT {$limit}",
            ['today' => $today ?? gmdate('Y-m-d')] + $bind,
        );

        return array_map(static fn (array $r): array => [
            'person_id' => (int) $r['person_id'], 'customer_name' => (string) $r['customer_name'], 'currency' => (string) $r['currency'],
            'invoices' => (int) $r['invoices'], 'outstanding' => (string) $r['outstanding'], 'overdue' => (string) $r['overdue'],
            'oldest_due' => $r['oldest_due'] ?? null,
        ], $rows);
    }

    /**
     * Issued / partially-paid invoices past their due date that still owe money (system-wide; for the reminder cron).
     *
     * @return list<array{id:int,invoice_number:string,branch_id:int,created_by:?int,due_on:string,currency:string,outstanding:string,customer_name:string}>
     */
    public function overdueForReminder(string $today, int $limit = 1000): array
    {
        $limit = max(1, min($limit, 5000));
        $owed = 'GREATEST(i.grand_total - (i.amount_paid - i.amount_refunded), 0)';
        $rows = $this->db->select(
            "SELECT i.id, i.invoice_number, i.branch_id, i.created_by, i.due_on, i.currency, {$owed} AS outstanding, p.full_name AS customer_name
             FROM invoices i JOIN persons p ON p.id = i.person_id
             WHERE i.status IN ('issued','partially_paid') AND i.due_on < :today AND {$owed} > 0 ORDER BY i.due_on, i.id LIMIT {$limit}",
            ['today' => $today],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'invoice_number' => (string) $r['invoice_number'], 'branch_id' => (int) $r['branch_id'],
            'created_by' => isset($r['created_by']) ? (int) $r['created_by'] : null, 'due_on' => (string) $r['due_on'],
            'currency' => (string) $r['currency'], 'outstanding' => (string) $r['outstanding'], 'customer_name' => (string) $r['customer_name'],
        ], $rows);
    }

    /** Writes a paid refund onto a locked invoice (money went back, so more is owed again) and bumps its version. */
    public function setRefundState(int $id, string $amountRefunded, string $status): void
    {
        $this->db->affectingStatement(
            'UPDATE invoices SET amount_refunded = :ref, status = :st, record_version = record_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['ref' => $amountRefunded, 'st' => $status, 'id' => $id],
        );
    }

    /**
     * Money position of the visible branches, per currency (a total across currencies would be meaningless).
     *
     * @return list<array{currency:string,billed:string,collected:string,outstanding:string,overdue:string}>
     */
    public function summary(BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $rows = $this->db->select(
            "SELECT currency,
                    SUM(grand_total) AS billed,
                    SUM(amount_paid - amount_refunded) AS collected,
                    SUM(GREATEST(grand_total - (amount_paid - amount_refunded), 0)) AS outstanding,
                    SUM(CASE WHEN due_on < :today THEN GREATEST(grand_total - (amount_paid - amount_refunded), 0) ELSE 0 END) AS overdue
             FROM invoices WHERE status IN ('issued','partially_paid','paid') AND {$branchSql} GROUP BY currency ORDER BY currency",
            ['today' => gmdate('Y-m-d')] + $bind,
        );

        return array_map(static fn (array $r): array => [
            'currency' => (string) $r['currency'], 'billed' => (string) $r['billed'], 'collected' => (string) $r['collected'],
            'outstanding' => (string) $r['outstanding'], 'overdue' => (string) $r['overdue'],
        ], $rows);
    }

    /** @return Page<Invoice> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue('SELECT COUNT(*) FROM invoices i JOIN persons p ON p.id = i.person_id WHERE ' . $where, $bind);

        $order = (self::SORT[$q->sort] ?? 'i.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, i.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Invoice::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('i.branch_id');
        $where = [$branchSql];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'i.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($type = $q->filter('type')) !== null && $type !== '') {
            $where[] = 'i.invoiceable_type = :f_type';
            $bind['f_type'] = $type;
        }
        if ($q->filter('due') === 'overdue') {
            $where[] = "i.status IN ('issued','partially_paid') AND i.due_on < :f_today AND i.grand_total > (i.amount_paid - i.amount_refunded)";
            $bind['f_today'] = gmdate('Y-m-d');
        }
        if ($q->hasSearch()) {
            $where[] = '(i.invoice_number = :s_exact OR p.full_name LIKE :s_name OR p.primary_phone LIKE :s_phone)';
            $bind['s_exact'] = $q->search;
            $bind['s_name'] = $bind['s_phone'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
