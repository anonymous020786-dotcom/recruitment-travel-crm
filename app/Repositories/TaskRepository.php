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
        t.branch_id, t.assigned_to, t.priority, t.due_date, t.due_time, t.status, t.completed_at, t.created_at,
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
