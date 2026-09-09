<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Seeder;

/**
 * The ten system roles. Permission assignment (role_permissions) and the full
 * permission catalogue are seeded in Step 1.7 by PermissionSeeder /
 * RolePermissionSeeder. Idempotent.
 */
final class RolesSeeder extends Seeder
{
    private const ROLES = [
        ['super_admin',   'Super Admin',        'Full control including RBAC, settings and audit.'],
        ['admin',         'Administrator',      'All operations, user management and settings.'],
        ['manager',       'Branch Manager',     'Full operational oversight of assigned branch(es).'],
        ['counselor',     'Counselor',          'Leads, candidates, follow-ups and communication.'],
        ['recruitment',   'Recruitment Team',   'Employers, jobs, applications and interviews.'],
        ['documentation', 'Documentation Team', 'Document upload, verification and expiry.'],
        ['visa',          'Visa Team',          'Medical, visa, travel and departure processing.'],
        ['accounts',      'Accounts',           'Invoices, payments, receipts and refunds.'],
        ['travel',        'Travel Team',        'Tour packages and travel bookings.'],
        ['read_only',     'Read Only',          'View-only access across operational modules.'],
    ];

    public function run(): void
    {
        $rows = array_map(static fn ($r) => [
            'name'        => $r[0],
            'label'       => $r[1],
            'description' => $r[2],
            'is_system'   => 1,
        ], self::ROLES);

        $n = $this->upsert('roles', $rows, ['label', 'description', 'is_system']);
        $this->info('roles: ' . count($rows) . " (affected {$n})");
    }
}
