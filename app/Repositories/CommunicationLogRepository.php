<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CommunicationLog;
use App\Support\Db;

/**
 * SQL for `communication_logs` — a polymorphic touchpoint log
 * (related_type/related_id). No update/delete: like activity_logs, a logged
 * contact is a record of what happened, not something to edit after the fact.
 */
final class CommunicationLogRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string,mixed> $data @return int new row id */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('communication_logs', $data);
    }

    public function findById(int $id): ?CommunicationLog
    {
        $row = $this->db->selectOne(
            'SELECT c.*, u.name AS user_name FROM communication_logs c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = :id',
            ['id' => $id],
        );

        return $row ? CommunicationLog::fromRow($row) : null;
    }

    /** @return list<CommunicationLog> most recent first */
    public function forRecord(string $relatedType, int $relatedId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $rows = $this->db->select(
            "SELECT c.*, u.name AS user_name
             FROM communication_logs c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.related_type = :t AND c.related_id = :id
             ORDER BY c.occurred_at DESC, c.id DESC LIMIT {$limit}",
            ['t' => $relatedType, 'id' => $relatedId],
        );

        return array_map([CommunicationLog::class, 'fromRow'], $rows);
    }

    public function countForRecord(string $relatedType, int $relatedId): int
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM communication_logs WHERE related_type = :t AND related_id = :id',
            ['t' => $relatedType, 'id' => $relatedId],
            0,
        );
    }
}
