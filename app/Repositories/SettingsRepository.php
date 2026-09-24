<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** Raw access to the `settings` key/value table. Which keys mean anything is decided by config/settings.php, not here. */
final class SettingsRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param list<string> $keys
     * @return array<string,mixed> key => decoded JSON value, for the keys that have a row
     */
    public function values(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $out = [];
        foreach ($this->db->select("SELECT key_name, value FROM settings WHERE key_name IN ({$in})", array_values($keys)) as $row) {
            $out[(string) $row['key_name']] = json_decode((string) $row['value'], true);
        }

        return $out;
    }

    public function put(string $key, mixed $value, bool $public, int $userId): void
    {
        $this->db->affectingStatement(
            'INSERT INTO settings (key_name, value, is_public, updated_by) VALUES (:k, :v, :p, :u)
             ON DUPLICATE KEY UPDATE value = VALUES(value), is_public = VALUES(is_public), updated_by = VALUES(updated_by)',
            ['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'p' => $public ? 1 : 0, 'u' => $userId],
        );
    }

    public function forget(string $key): void
    {
        $this->db->affectingStatement('DELETE FROM settings WHERE key_name = :k', ['k' => $key]);
    }
}
