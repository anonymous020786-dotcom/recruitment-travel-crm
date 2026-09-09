<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Seeder;

/**
 * Seeds the `permissions` table from config('permissions.catalogue').
 * Idempotent. Adding/removing a permission is a config change + re-seed
 * (and, for removals, a cleanup of role_permissions handled here).
 */
final class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = (array) $this->app->config()->get('permissions.catalogue', []);

        $rows = [];
        foreach ($catalogue as $module => $actions) {
            foreach ((array) $actions as $action => $label) {
                $rows[] = [
                    'name'   => "{$module}.{$action}",
                    'module' => (string) $module,
                    'label'  => (string) $label,
                ];
            }
        }

        $n = $this->upsert('permissions', $rows, ['module', 'label']);
        $this->info('permissions: ' . count($rows) . " (affected {$n})");

        // Drop permissions no longer in the catalogue (and their role links).
        $names = array_column($rows, 'name');
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stale = $this->db->select(
            "SELECT id, name FROM permissions WHERE name NOT IN ({$placeholders})",
            $names,
        );
        foreach ($stale as $row) {
            $this->db->affectingStatement('DELETE FROM role_permissions WHERE permission_id = ?', [$row['id']]);
            $this->db->affectingStatement('DELETE FROM user_permissions WHERE permission_id = ?', [$row['id']]);
            $this->db->affectingStatement('DELETE FROM permissions WHERE id = ?', [$row['id']]);
            $this->info("  removed stale permission: {$row['name']}");
        }
    }
}
