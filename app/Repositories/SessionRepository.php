<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * Read/side access to the `sessions` table for the account-security screen
 * (list active sessions, revoke one, "sign out everywhere").
 */
final class SessionRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly string $table = 'sessions',
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            "SELECT id, ip_address, user_agent, last_activity, created_at
             FROM `{$this->table}` WHERE user_id = :uid ORDER BY last_activity DESC",
            ['uid' => $userId],
        );
    }

    public function deleteForUserExcept(int $userId, string $keepId): int
    {
        return $this->db->affectingStatement(
            "DELETE FROM `{$this->table}` WHERE user_id = :uid AND id <> :keep",
            ['uid' => $userId, 'keep' => $keepId],
        );
    }

    public function deleteOne(int $userId, string $id): int
    {
        return $this->db->affectingStatement(
            "DELETE FROM `{$this->table}` WHERE user_id = :uid AND id = :id",
            ['uid' => $userId, 'id' => $id],
        );
    }
}
