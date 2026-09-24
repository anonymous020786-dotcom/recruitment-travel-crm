<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Payment;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `payments`, branch-scoped on `payments.branch_id`, optimistic-locked on `record_version`. */
final class PaymentRepository
{
    public const SORT = [
        'paid_at'  => 'pm.paid_at',
        'number'   => 'pm.payment_number',
        'customer' => 'p.full_name',
        'amount'   => 'pm.amount',
        'status'   => 'pm.status',
    ];

    public const FILTER_KEYS = ['status', 'method', 'credit'];

    private const COLUMNS = 'pm.id, pm.public_id, pm.payment_number, pm.receipt_number, pm.person_id, pm.branch_id, pm.amount, pm.currency,
        pm.method, pm.reference, pm.paid_at, pm.status, pm.reversed_reason, pm.notes, pm.record_version, pm.created_at,
        p.full_name AS customer_name, p.primary_phone AS customer_phone, u.name AS received_by,
        (SELECT COALESCE(SUM(a.amount), 0) FROM payment_allocations a WHERE a.payment_id = pm.id) AS allocated';

    private const JOINS = 'FROM payments pm JOIN persons p ON p.id = pm.person_id LEFT JOIN users u ON u.id = pm.created_by';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Payment
    {
        [$branchSql, $bind] = $scope->whereClause('pm.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE pm.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? Payment::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Payment
    {
        [$branchSql, $bind] = $scope->whereClause('pm.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE pm.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? Payment::fromRow($row) : null;
    }

    /** The payment previously recorded with an idempotency key, if any. */
    public function findByIdempotencyKey(string $key, BranchScope $scope): ?Payment
    {
        [$branchSql, $bind] = $scope->whereClause('pm.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE pm.idempotency_key = :k AND {$branchSql}", ['k' => $key] + $bind);

        return $row ? Payment::fromRow($row) : null;
    }

    /** @return list<Payment> a person's still-recorded payments that have money left to allocate, oldest first */
    public function withCreditForPerson(int $personId, string $currency, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('pm.branch_id');
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS
            . " WHERE pm.person_id = :p AND pm.currency = :c AND pm.status = 'recorded' AND {$branchSql}
                HAVING pm.amount > allocated ORDER BY pm.paid_at, pm.id",
            ['p' => $personId, 'c' => $currency] + $bind,
        );

        return array_map([Payment::class, 'fromRow'], $rows);
    }

    /** A refund that is still alive (requested, approved or paid) against this payment. */
    public function hasOpenRefund(int $paymentId): bool
    {
        return $this->db->exists("SELECT 1 FROM refunds WHERE payment_id = :p AND status IN ('pending','approved','paid')", ['p' => $paymentId]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('payments', $data);
    }

    /**
     * Optimistic write.
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
            'UPDATE payments SET ' . implode(', ', $set) . " WHERE id = :id AND record_version = :ver AND {$branchSql}",
            $bind,
        );
    }

    /** @return Page<Payment> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM payments pm JOIN persons p ON p.id = pm.person_id WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'pm.paid_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, pm.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Payment::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('pm.branch_id');
        $where = [$branchSql];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'pm.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($method = $q->filter('method')) !== null && $method !== '') {
            $where[] = 'pm.method = :f_method';
            $bind['f_method'] = $method;
        }
        if ($q->filter('credit') === 'unallocated') {
            $where[] = "pm.status = 'recorded' AND pm.amount > (SELECT COALESCE(SUM(a.amount), 0) FROM payment_allocations a WHERE a.payment_id = pm.id)";
        }
        if ($q->hasSearch()) {
            $where[] = '(pm.payment_number = :s_pay OR pm.receipt_number = :s_rct OR p.full_name LIKE :s_name OR pm.reference LIKE :s_ref)';
            $bind['s_pay'] = $bind['s_rct'] = $q->search;
            $bind['s_name'] = $bind['s_ref'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
