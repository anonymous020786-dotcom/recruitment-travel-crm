<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

final class LoginHistoryRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM login_history WHERE user_id = :id', ['id' => $userId], 0);
    }

    public function seenFingerprint(int $userId, string $uaHash): bool
    {
        return $this->db->exists(
            'SELECT 1 FROM login_history WHERE user_id = :id AND ua_hash = :h',
            ['id' => $userId, 'h' => $uaHash],
        );
    }

    public function record(int $userId, ?string $ipBinary, string $uaHash, string $userAgent, string $via, bool $alerted): int
    {
        return (int) $this->db->insertRow('login_history', [
            'user_id'    => $userId,
            'ip_address' => $ipBinary,
            'ua_hash'    => $uaHash,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'via'        => mb_substr($via, 0, 20),
            'alerted'    => $alerted ? 1 : 0,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recentForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        return $this->db->select(
            "SELECT ip_address, user_agent, via, created_at FROM login_history
             WHERE user_id = :id ORDER BY id DESC LIMIT {$limit}",
            ['id' => $userId],
        );
    }
}
