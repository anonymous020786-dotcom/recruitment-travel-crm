<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Support\Db;

/**
 * Read-only queries over `activity_logs` for the audit-log viewer (ActivityLogRepository stays the append-only writer).
 *
 * The audit trail has no branch of its own, so a viewer who is not organisation-wide sees the actions taken by people who
 * share one of their branches (never system/cron rows, never people in other branches). Filters are bound values; the
 * sort is fixed (newest first) so no user input reaches ORDER BY, and the row count is capped so a broad query cannot
 * make the database count millions of rows.
 */
final class AuditLogRepository
{
    public const COUNT_CAP = 10000;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array{module:string,q:string,from:string,to:string,record_type:string,record_id:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,capped:bool}
     */
    public function search(BranchScope $scope, array $f, int $page, int $perPage): array
    {
        [$where, $bind] = $this->filter($scope, $f);
        $limit = max(1, min($perPage, 100));
        $offset = (max(1, $page) - 1) * $limit;
        $cap = self::COUNT_CAP + 1;

        $counted = (int) $this->db->selectValue("SELECT COUNT(*) FROM (SELECT 1 FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$where} LIMIT {$cap}) c", $bind);
        $rows = $this->db->select(
            "SELECT l.id, l.created_at, l.user_id, u.name AS user_name, l.action, l.module, l.record_type, l.record_id,
                    l.context, l.old_values, l.new_values, l.ip_address, l.user_agent
             FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
             WHERE {$where} ORDER BY l.created_at DESC, l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return ['rows' => $rows, 'total' => min($counted, self::COUNT_CAP), 'capped' => $counted > self::COUNT_CAP];
    }

    /** @return list<string> the modules that have audit rows, for the filter dropdown */
    public function modules(): array
    {
        return array_map(static fn (array $r): string => (string) $r['module'], $this->db->select('SELECT DISTINCT module FROM activity_logs ORDER BY module'));
    }

    /**
     * @param array{module:string,q:string,from:string,to:string,record_type:string,record_id:string} $f
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filter(BranchScope $scope, array $f): array
    {
        $where = ['l.created_at >= :from', 'l.created_at < DATE_ADD(:to, INTERVAL 1 DAY)'];
        $bind = ['from' => $f['from'], 'to' => $f['to']];

        if (!$scope->orgWide) {
            [$branchSql, $branchBind] = $scope->whereClause('ub.branch_id');
            $where[] = "l.user_id IN (SELECT ub.user_id FROM user_branches ub WHERE {$branchSql})";
            $bind += $branchBind;
        }
        if ($f['module'] !== '') {
            $where[] = 'l.module = :module';
            $bind['module'] = $f['module'];
        }
        if ($f['record_type'] !== '') {
            $where[] = 'l.record_type = :rtype';
            $bind['rtype'] = $f['record_type'];
            if ($f['record_id'] !== '') {
                $where[] = 'l.record_id = :rid';
                $bind['rid'] = $f['record_id'];
            }
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(l.action LIKE :q1 OR l.record_type LIKE :q2 OR l.context LIKE :q3 OR u.name LIKE :q4 OR u.email LIKE :q5)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) {
                $bind[$k] = $like;
            }
        }

        return [implode(' AND ', $where), $bind];
    }
}
