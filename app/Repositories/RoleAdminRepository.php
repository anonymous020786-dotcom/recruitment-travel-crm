<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** SQL for the admin "Roles" screens: the roles, the permission catalogue, and each role's grants. */
final class RoleAdminRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id:int,name:string,label:string,description:?string,is_system:bool,permissions:int,users:int}> */
    public function roles(): array
    {
        $rows = $this->db->select(
            'SELECT r.id, r.name, r.label, r.description, r.is_system,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permissions,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS users
             FROM roles r ORDER BY r.id',
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'label' => (string) $r['label'],
            'description' => $r['description'] !== null ? (string) $r['description'] : null,
            'is_system' => (bool) $r['is_system'], 'permissions' => (int) $r['permissions'], 'users' => (int) $r['users'],
        ], $rows);
    }

    /** @return array{id:int,name:string,label:string,description:?string,is_system:bool,permissions:int,users:int}|null */
    public function findByName(string $name): ?array
    {
        foreach ($this->roles() as $role) {
            if ($role['name'] === $name) {
                return $role;
            }
        }

        return null;
    }

    /** @return array<string,list<array{id:int,name:string,label:string}>> module => permissions, in catalogue order */
    public function catalogue(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT id, name, module, label FROM permissions ORDER BY module, name') as $r) {
            $out[(string) $r['module']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'label' => (string) $r['label']];
        }

        return $out;
    }

    /** @return list<string> permission names granted to the role, sorted */
    public function grantedNames(int $roleId): array
    {
        $names = array_map(static fn (array $r): string => (string) $r['name'], $this->db->select(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r',
            ['r' => $roleId],
        ));
        sort($names);

        return $names;
    }

    /**
     * Makes the role hold exactly $names (unknown names are ignored — the service validates first).
     *
     * @param list<string> $names
     * @return array{added:list<string>,removed:list<string>}
     */
    public function sync(int $roleId, array $names): array
    {
        $ids = [];
        foreach ($this->db->select('SELECT id, name FROM permissions') as $p) {
            $ids[(string) $p['name']] = (int) $p['id'];
        }
        $current = $this->grantedNames($roleId);
        $added = array_values(array_diff($names, $current));
        $removed = array_values(array_diff($current, $names));

        foreach ($added as $name) {
            if (isset($ids[$name])) {
                $this->db->affectingStatement('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:r, :p)', ['r' => $roleId, 'p' => $ids[$name]]);
            }
        }
        foreach ($removed as $name) {
            if (isset($ids[$name])) {
                $this->db->affectingStatement('DELETE FROM role_permissions WHERE role_id = :r AND permission_id = :p', ['r' => $roleId, 'p' => $ids[$name]]);
            }
        }
        sort($added);
        sort($removed);

        return ['added' => $added, 'removed' => $removed];
    }
}
