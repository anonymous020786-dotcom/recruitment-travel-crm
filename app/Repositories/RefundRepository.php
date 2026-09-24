<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Sql;
use App\Auth\BranchScope;
use App\Models\Refund;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `refunds`, branch-scoped on `refunds.branch_id`, optimistic-locked on `record_version`. */
final class RefundRepository
{
    public const SORT = [
        'created_at' => 'r.created_at',
        'number'     => 'r.refund_number',
        'customer'   => 'p.full_name',
        'amount'     => 'r.amount',
        'status'     => 'r.status',
    ];

    public const FILTER_KEYS = ['status'];

    public const STATUSES = ['pending', 'approved', 'paid', 'rejected'];

    private const COLUMNS = 'r.id, r.public_id, r.refund_number, r.payment_id, r.invoice_id, r.person_id, r.branch_id, r.amount, r.currency,
        r.method, r.reason, r.status, r.approved_at, r.refunded_at, r.record_version, r.created_by, r.created_at,
        pm.public_id AS payment_public_id, pm.payment_number, i.public_id AS invoice_public_id, i.invoice_number,
        p.full_name AS customer_name, cu.name AS requested_by, au.name AS approved_by_name';

    private const JOINS = 'FROM refunds r
        JOIN payments pm ON pm.id = r.payment_id
        JOIN persons p ON p.id = r.person_id
        LEFT JOIN invoices i ON i.id = r.invoice_id
        LEFT JOIN users cu ON cu.id = r.created_by
        LEFT JOIN users au ON au.id = r.approved_by';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Refund
    {
        [$branchSql, $bind] = $scope->whereClause('r.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE r.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? Refund::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Refund
    {
        [$branchSql, $bind] = $scope->whereClause('r.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE r.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? Refund::fromRow($row) : null;
    }

    /** @return list<Refund> a payment's refunds, newest first */
    public function forPayment(int $paymentId): array
    {
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE r.payment_id = :p ORDER BY r.id DESC', ['p' => $paymentId]);

        return array_map([Refund::class, 'fromRow'], $rows);
    }

    /**
     * Sum (2 dp string) of the payment's refunds that still reserve money, for one invoice
     * (`$invoiceId` set) or for the un-invoiced credit (`null`).
     */
    public function activeSum(int $paymentId, ?int $invoiceId): string
    {
        return (string) $this->db->selectValue(
            "SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE payment_id = :p AND invoice_id <=> :i AND status IN ('pending','approved','paid')",
            ['p' => $paymentId, 'i' => $invoiceId],
            '0',
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('refunds', $data);
    }

    /**
     * @param array<string,mixed> $changes
     * @return int rows affected (0 => stale, or outside scope)
     */
    public function updateVersioned(int $id, array $changes, int $expectedVersion, BranchScope $scope): int
    {
        [$branchSql, $branchBind] = $scope->whereClause('branch_id');

        $set = ['record_version = record_version + 1', 'updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id, 'ver' => $expectedVersion] + $branchBind;
        foreach ($changes as $col => $val) {
            $set[] = Sql::assign($col, 'c_');
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE refunds SET ' . implode(', ', $set) . " WHERE id = :id AND record_version = :ver AND {$branchSql}",
            $bind,
        );
    }

    /** @return array<string,int> count per status (every status present) */
    public function statusCounts(BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $rows = $this->db->select("SELECT status, COUNT(*) AS n FROM refunds WHERE {$branchSql} GROUP BY status", $bind);

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }

        return $counts;
    }

    /** @return Page<Refund> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue('SELECT COUNT(*) FROM refunds r JOIN persons p ON p.id = r.person_id JOIN payments pm ON pm.id = r.payment_id WHERE ' . $where, $bind);

        $order = (self::SORT[$q->sort] ?? 'r.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Refund::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('r.branch_id');
        $where = [$branchSql];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'r.status = :f_status';
            $bind['f_status'] = $status;
        }
        if ($q->hasSearch()) {
            $where[] = '(r.refund_number = :s_ref OR pm.payment_number = :s_pay OR p.full_name LIKE :s_name)';
            $bind['s_ref'] = $bind['s_pay'] = $q->search;
            $bind['s_name'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
