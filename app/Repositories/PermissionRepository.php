<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

class PermissionRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<string> permission names granted to the role */
    public function namesForRole(int $roleId): array
    {
        $rows = $this->db->select(
            'SELECT p.name FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :rid',
            ['rid' => $roleId],
        );

        return array_map(static fn ($r) => (string) $r['name'], $rows);
    }

    /** @return array<string,string> permission name => 'allow'|'deny' */
    public function overridesForUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT p.name, up.effect FROM user_permissions up
             JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = :uid AND (up.expires_at IS NULL OR up.expires_at > UTC_TIMESTAMP())',
            ['uid' => $userId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = (string) $row['effect'];
        }

        return $out;
    }

    /** @return list<string> every permission name in the catalogue */
    public function allNames(): array
    {
        return array_map(
            static fn ($r) => (string) $r['name'],
            $this->db->select('SELECT name FROM permissions ORDER BY name'),
        );
    }
}
