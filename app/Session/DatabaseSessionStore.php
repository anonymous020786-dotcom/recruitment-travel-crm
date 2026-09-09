<?php

declare(strict_types=1);

namespace App\Session;

use App\Support\Db;

/**
 * Session payloads in the `sessions` table (JSON, not PHP-serialised — no
 * object-injection surface). Garbage collection runs from cron/cleanup.php, not
 * per request.
 */
final class DatabaseSessionStore implements SessionStore
{
    public function __construct(
        private readonly Db $db,
        private readonly string $table = 'sessions',
    ) {
    }

    public function read(string $id): array
    {
        $row = $this->db->selectOne(
            "SELECT payload FROM `{$this->table}` WHERE id = :id",
            ['id' => $id],
        );

        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) $row['payload'], true);

        return is_array($decoded) ? $decoded : [];
    }

    public function write(string $id, array $data, array $meta = []): void
    {
        $this->db->affectingStatement(
            "INSERT INTO `{$this->table}` (id, user_id, ip_address, user_agent, payload, last_activity, created_at)
             VALUES (:id, :user_id, :ip, :ua, :payload, :last_activity, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                payload = VALUES(payload),
                last_activity = VALUES(last_activity)",
            [
                'id'            => $id,
                'user_id'       => $meta['user_id'] ?? ($data['_auth_user_id'] ?? null),
                'ip'            => $meta['ip'] ?? null,
                'ua'            => isset($meta['user_agent']) ? substr((string) $meta['user_agent'], 0, 255) : null,
                'payload'       => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'last_activity' => time(),
            ],
        );
    }

    public function destroy(string $id): void
    {
        $this->db->affectingStatement("DELETE FROM `{$this->table}` WHERE id = :id", ['id' => $id]);
    }

    public function gc(int $maxLifetimeSeconds): int
    {
        return $this->db->affectingStatement(
            "DELETE FROM `{$this->table}` WHERE last_activity < :cutoff",
            ['cutoff' => time() - $maxLifetimeSeconds],
        );
    }
}
