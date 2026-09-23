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
