<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * The audit trail is append-only by design: this repository exposes `insert`
 * and read methods only — no update, no delete. Retention/archival is a
 * deliberate, separate operation.
 */
final class ActivityLogRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string,mixed>|null $oldValues
     * @param array<string,mixed>|null $newValues
     */
    public function insert(
        ?int $userId,
        string $action,
        string $module,
        string $recordType,
        int|string|null $recordId,
        ?array $oldValues,
        ?array $newValues,
        ?string $ipBinary,
        ?string $userAgent,
        ?string $context,
    ): void {
        $this->db->affectingStatement(
            'INSERT INTO activity_logs
                (user_id, action, module, record_type, record_id, old_values, new_values,
                 ip_address, user_agent, context, created_at)
             VALUES (:uid, :action, :module, :rtype, :rid, :old, :new, :ip, :ua, :ctx, UTC_TIMESTAMP())',
            [
                'uid'    => $userId,
                'action' => mb_substr($action, 0, 60),
                'module' => mb_substr($module, 0, 40),
                'rtype'  => mb_substr($recordType, 0, 40),
                'rid'    => $recordId !== null ? (string) $recordId : null,
                'old'    => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_SLASHES) : null,
                'new'    => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_SLASHES) : null,
                'ip'     => $ipBinary,
                'ua'     => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'ctx'    => $context !== null ? mb_substr($context, 0, 255) : null,
            ],
        );
    }

    /** @return list<array<string,mixed>> */
    public function forRecord(string $recordType, int|string $recordId, int $limit = 100): array
    {
        // LIMIT is a clamped integer literal, never user input.
        $limit = max(1, min($limit, 500));

        return $this->db->select(
            "SELECT * FROM activity_logs WHERE record_type = :t AND record_id = :id
             ORDER BY created_at DESC, id DESC LIMIT {$limit}",
            ['t' => $recordType, 'id' => (string) $recordId],
        );
    }
}
