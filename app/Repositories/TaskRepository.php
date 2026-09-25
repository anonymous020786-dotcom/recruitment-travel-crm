<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Exceptions\QueryException;
use App\Models\Task;
use App\Support\Db;

/**
 * SQL for the generic `tasks` table (polymorphic `related_type`/`related_id`).
 * Reads are branch-scoped; only the candidate-linked queries are exercised so
 * far, but nothing here assumes `related_type = 'candidate'`.
 */
final class TaskRepository
{
    private const COLUMNS = 't.id, t.public_id, t.title, t.description, t.related_type, t.related_id,
        t.branch_id, t.assigned_to, t.priority, t.due_date, t.due_time, t.status, t.completed_at, t.created_at, t.created_by, t.source,
        u.name AS assignee_name';

    private const JOINS = 'FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<Task> pending first, then soonest due */
    public function forRelated(string $relatedType, int $relatedId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS
            . ' WHERE t.related_type = :rt AND t.related_id = :rid
               ORDER BY t.status = \'pending\' DESC, t.due_date IS NULL, t.due_date ASC, t.id DESC',
            ['rt' => $relatedType, 'rid' => $relatedId],
        );

        return array_map([Task::class, 'fromRow'], $rows);
    }

    public function findInScope(int $id, BranchScope $scope): ?Task
    {
        [$branchSql, $bind] = $scope->whereClause('t.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE t.id = :id AND {$branchSql}",
            ['id' => $id] + $bind,
        );

        return $row ? Task::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Task
    {
        [$branchSql, $bind] = $scope->whereClause('t.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE t.public_id = :p AND {$branchSql}",
            ['p' => $publicId] + $bind,
        );

        return $row ? Task::fromRow($row) : null;
    }

    // ---- the task centre ----------------------------------------------------------------------------------------

    public const TABS = ['open', 'overdue', 'today', 'done', 'cancelled', 'all'];
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    public const RELATED_TYPES = ['lead', 'candidate', 'application', 'payment', 'invoice', 'visa', 'travel', 'employer', 'tour_booking', 'none'];

    /** Where each kind of record lives: table, and the screen prefix (null = only a list screen exists). */
    private const RELATED = [
        'lead' => ['leads', '/leads/'], 'candidate' => ['candidates', '/candidates/'], 'application' => ['applications', '/applications/'],
        'payment' => ['payments', '/payments/'], 'invoice' => ['invoices', '/invoices/'], 'visa' => ['visa_applications', '/visa/'],
        'travel' => ['flight_bookings', null], 'employer' => ['employers', '/employers/'], 'tour_booking' => ['tour_bookings', '/tours/bookings/'],
    ];

    /**
     * One page of tasks the viewer may see: inside their branches, and — unless they may see everyone's — only tasks assigned
     * to or created by them.
     *
     * @param array{tab:string,priority:string,related:string,assignee:int,q:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function page(BranchScope $scope, int $viewerId, bool $seeAll, array $f, int $page, int $perPage): array
    {
        [$where, $bind] = $this->visible($scope, $viewerId, $seeAll);
        $where = array_merge($where, $this->tabWhere($f['tab'], $bind));
        if (in_array($f['priority'], self::PRIORITIES, true)) {
            $where[] = 't.priority = :prio';
            $bind['prio'] = $f['priority'];
        }
        if (in_array($f['related'], self::RELATED_TYPES, true)) {
            $where[] = 't.related_type = :rtype';
            $bind['rtype'] = $f['related'];
        }
        if ($seeAll && $f['assignee'] > 0) {
            $where[] = 't.assigned_to = :assignee';
            $bind['assignee'] = $f['assignee'];
        }
        if ($f['q'] !== '') {
            $where[] = 't.title LIKE :q';
            $bind['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }
        $condition = implode(' AND ', $where);
        $limit = max(1, min($perPage, 100));
        $offset = (max(1, $page) - 1) * $limit;
        $order = in_array($f['tab'], ['done', 'cancelled'], true)
            ? 't.completed_at DESC, t.id DESC'
            : "t.due_date IS NULL, t.due_date ASC, FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.id DESC";

        return [
            'total' => (int) $this->db->selectValue("SELECT COUNT(*) FROM tasks t WHERE {$condition}", $bind),
            'rows' => $this->db->select('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$condition} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}", $bind),
        ];
    }

    /**
     * How many tasks sit in each tab, for the tab badges (visibility applies; other filters do not).
     *
     * @return array<string,int>
     */
    public function tabCounts(BranchScope $scope, int $viewerId, bool $seeAll): array
    {
        [$where, $bind] = $this->visible($scope, $viewerId, $seeAll);
        $bind['today1'] = $bind['today2'] = gmdate('Y-m-d');
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(t.status = 'pending'), 0) AS open,
                    COALESCE(SUM(t.status = 'pending' AND t.due_date < :today1), 0) AS overdue,
                    COALESCE(SUM(t.status = 'pending' AND t.due_date = :today2), 0) AS today,
                    COALESCE(SUM(t.status = 'completed'), 0) AS done,
                    COALESCE(SUM(t.status = 'cancelled'), 0) AS cancelled
             FROM tasks t WHERE " . implode(' AND ', $where),
            $bind,
        ) ?? [];

        return [
            'all' => (int) ($row['total'] ?? 0), 'open' => (int) ($row['open'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0),
            'today' => (int) ($row['today'] ?? 0), 'done' => (int) ($row['done'] ?? 0), 'cancelled' => (int) ($row['cancelled'] ?? 0),
        ];
    }

    /**
     * The viewer's own open work: pending tasks assigned to them, how many are overdue and how many are due today.
     *
     * @return array{open:int,overdue:int,today:int}
     */
    public function countsForAssignee(int $userId): array
    {
        $today = gmdate('Y-m-d');
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS open, COALESCE(SUM(due_date < :t1), 0) AS overdue, COALESCE(SUM(due_date = :t2), 0) AS today
             FROM tasks WHERE assigned_to = :u AND status = 'pending'",
            ['t1' => $today, 't2' => $today, 'u' => $userId],
        ) ?? [];

        return ['open' => (int) ($row['open'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0), 'today' => (int) ($row['today'] ?? 0)];
    }

    /**
     * The screen each row's linked record lives on, keyed by task id (a task with no record has no entry).
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,string>
     */
    public function linksFor(array $rows): array
    {
        $byType = [];
        foreach ($rows as $r) {
            if (isset(self::RELATED[(string) $r['related_type']]) && $r['related_id'] !== null) {
                $byType[(string) $r['related_type']][(int) $r['related_id']][] = (int) $r['id'];
            }
        }
        $links = [];
        foreach ($byType as $type => $ids) {
            [$table, $prefix] = self::RELATED[$type];
            if ($prefix === null) {
                foreach ($ids as $taskIds) {
                    foreach ($taskIds as $tid) {
                        $links[$tid] = '/travel';
                    }
                }
                continue;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->select("SELECT id, public_id FROM {$table} WHERE id IN ({$in})", array_keys($ids)) as $rec) {
                foreach ($ids[(int) $rec['id']] ?? [] as $tid) {
                    $links[$tid] = $prefix . $rec['public_id'];
                }
            }
        }

        return $links;
    }

    public function reassign(int $id, int $userId, int $branchId): int
    {
        return $this->db->affectingStatement(
            "UPDATE tasks SET assigned_to = :u, branch_id = :b WHERE id = :id AND status = 'pending'",
            ['u' => $userId, 'b' => $branchId, 'id' => $id],
        );
    }

    /**
     * Active people a task may be given to within the viewer's branches.
     *
     * @return list<array{id:int,name:string,branch_id:int}>
     */
    public function assignable(BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('u.primary_branch_id');
        $rows = $this->db->select(
            "SELECT u.id, u.name, u.primary_branch_id AS branch_id FROM users u
             WHERE u.is_active = 1 AND u.deleted_at IS NULL AND u.primary_branch_id IS NOT NULL AND {$branchSql} ORDER BY u.name LIMIT 300",
            $bind,
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'branch_id' => (int) $r['branch_id']], $rows);
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function visible(BranchScope $scope, int $viewerId, bool $seeAll): array
    {
        [$branchSql, $bind] = $scope->whereClause('t.branch_id');
        $where = [$branchSql];
        if (!$seeAll) {
            $where[] = '(t.assigned_to = :me1 OR t.created_by = :me2)';
            $bind['me1'] = $bind['me2'] = $viewerId;
        }

        return [$where, $bind];
    }

    /**
     * @param array<string,mixed> $bind
     * @return list<string>
     */
    private function tabWhere(string $tab, array &$bind): array
    {
        if ($tab === 'overdue' || $tab === 'today') {
            $bind['tab_today'] = gmdate('Y-m-d');

            return ["t.status = 'pending'", 't.due_date ' . ($tab === 'overdue' ? '<' : '=') . ' :tab_today'];
        }

        return match ($tab) {
            'done' => ["t.status = 'completed'"],
            'cancelled' => ["t.status = 'cancelled'"],
            'all' => [],
            default => ["t.status = 'pending'"],
        };
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('tasks', $data);
    }

    /**
     * Creates a system task unless one with the same `dedupe_key` exists (cron re-runs stay idempotent).
     *
     * @param array<string,mixed> $data must include `dedupe_key`
     * @return bool whether a task was created
     */
    public function createOnce(array $data): bool
    {
        try {
            $this->db->insertRow('tasks', $data + ['source' => 'system']);

            return true;
        } catch (QueryException $e) {
            return $e->isDuplicateKey() ? false : throw $e;
        }
    }

    /** Whether a pending system-created task is already open against a record. */
    public function hasPendingSystemTask(string $relatedType, int $relatedId): bool
    {
        return $this->db->exists(
            "SELECT 1 FROM tasks WHERE related_type = :rt AND related_id = :rid AND source = 'system' AND status = 'pending'",
            ['rt' => $relatedType, 'rid' => $relatedId],
        );
    }

    public function markCompleted(int $id): int
    {
        return $this->db->affectingStatement(
            "UPDATE tasks SET status = 'completed', completed_at = UTC_TIMESTAMP() WHERE id = :id AND status = 'pending'",
            ['id' => $id],
        );
    }

    public function markCancelled(int $id): int
    {
        return $this->db->affectingStatement(
            "UPDATE tasks SET status = 'cancelled' WHERE id = :id AND status = 'pending'",
            ['id' => $id],
        );
    }
}
