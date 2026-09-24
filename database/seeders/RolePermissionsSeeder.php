<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Auth\PermissionMatrix;
use App\Support\Seeder;

/**
 * Seeds `role_permissions` from config('permissions.matrix').
 *
 * Matrix tokens:
 *   'module.action'  grant one permission
 *   'module.*'       grant every permission in that module
 *   '*'              grant every permission
 *   '!module.action' / '!module.*'  revoke (applied after grants)
 *
 * Idempotent: the seeder computes the desired set per role and syncs
 * role_permissions to exactly that set. Custom grants an admin later adds
 * through the UI will be reset by a re-seed — document that, or gate re-seeding.
 */
final class RolePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $matrix = (array) $this->app->config()->get('permissions.matrix', []);

        $allPermissions = [];
        foreach ($this->db->select('SELECT id, name, module FROM permissions') as $row) {
            $allPermissions[$row['name']] = (int) $row['id'];
        }
        $permByModule = [];
        foreach ($this->db->select('SELECT name, module FROM permissions') as $row) {
            $permByModule[$row['module']][] = $row['name'];
        }

        foreach ($this->db->select('SELECT id, name FROM roles') as $role) {
            $tokens = (array) ($matrix[$role['name']] ?? []);
            $desired = PermissionMatrix::resolve($tokens, array_keys($allPermissions), $permByModule);

            $desiredIds = [];
            foreach ($desired as $name) {
                if (isset($allPermissions[$name])) {
                    $desiredIds[$allPermissions[$name]] = true;
                }
            }

            $current = [];
            foreach ($this->db->select('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$role['id']]) as $rp) {
                $current[(int) $rp['permission_id']] = true;
            }

            $toAdd = array_diff_key($desiredIds, $current);
            $toRemove = array_diff_key($current, $desiredIds);

            foreach (array_keys($toAdd) as $pid) {
                $this->db->affectingStatement(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                    [$role['id'], $pid],
                );
            }
            if ($toRemove !== []) {
                $ph = implode(',', array_fill(0, count($toRemove), '?'));
                $this->db->affectingStatement(
                    "DELETE FROM role_permissions WHERE role_id = ? AND permission_id IN ({$ph})",
                    [$role['id'], ...array_keys($toRemove)],
                );
            }

            $this->info(sprintf(
                'role %-14s -> %d permissions (+%d -%d)',
                $role['name'], count($desiredIds), count($toAdd), count($toRemove),
            ));
        }
    }
}
