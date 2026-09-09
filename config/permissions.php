<?php

declare(strict_types=1);

/**
 * The permission catalogue and the default role → permission matrix.
 *
 * `catalogue`  module => [action => human label]. Seeded into `permissions`.
 * `matrix`     role machine name => list of permission names or `module.*`
 *              wildcards (expanded by RolePermissionsSeeder).
 *
 * This is the SEED default. Agencies tune role_permissions live via
 * `roles.manage`; system invariants (append-only audit, transition allowlists,
 * branch isolation, money rules) are enforced in code and are NOT overridable
 * here. See docs/02-RBAC.md.
 */

return [

    'catalogue' => [

        'system' => [
            'health'  => 'View system health / readiness',
            'console' => 'View system console (version, queues, storage)',
        ],

        'dashboard' => [
            'view' => 'View the dashboard',
        ],

        'search' => [
            'global' => 'Use global search',
        ],

        'notifications' => [
            'view' => 'View own notifications',
        ],

        'leads' => [
            'view' => 'View leads', 'view_all' => 'View leads across all branches',
            'create' => 'Create leads', 'edit' => 'Edit leads', 'delete' => 'Delete leads',
            'assign' => 'Assign leads', 'import' => 'Import leads', 'export' => 'Export leads',
            'convert' => 'Convert a lead to a candidate', 'merge' => 'Merge duplicate leads',
        ],

        'followups' => [
            'view' => 'View follow-ups', 'create' => 'Create follow-ups', 'edit' => 'Edit follow-ups',
            'complete' => 'Complete follow-ups', 'delete' => 'Delete follow-ups',
        ],

        'persons' => [
            'view' => 'View person records', 'merge' => 'Merge person identities',
        ],

        'candidates' => [
            'view' => 'View candidates', 'view_all' => 'View candidates across all branches',
            'create' => 'Create candidates', 'edit' => 'Edit candidates', 'delete' => 'Delete candidates',
            'export' => 'Export candidates',
            'education.manage' => 'Manage candidate education', 'experience.manage' => 'Manage candidate experience',
            'skills.manage' => 'Manage candidate skills', 'preferences.manage' => 'Manage candidate preferences',
            'passport.manage' => 'Manage candidate passports',
        ],

        'documents' => [
            'view' => 'View documents', 'upload' => 'Upload documents', 'verify' => 'Verify documents',
            'reject' => 'Reject documents', 'delete' => 'Delete documents', 'download' => 'Download documents',
            'checklist.manage' => 'Manage document checklists',
        ],

        'employers' => [
            'view' => 'View employers', 'view_all' => 'View employers across all branches',
            'create' => 'Create employers', 'edit' => 'Edit employers', 'delete' => 'Delete employers',
            'export' => 'Export employers', 'contacts.manage' => 'Manage employer contacts',
        ],

        'jobs' => [
            'view' => 'View jobs', 'create' => 'Create jobs', 'edit' => 'Edit jobs', 'delete' => 'Delete jobs',
            'change_status' => 'Change job status', 'publish' => 'Publish jobs to the public site',
            'export' => 'Export jobs', 'match' => 'Run job matching',
        ],

        'applications' => [
            'view' => 'View applications', 'view_all' => 'View applications across all branches',
            'create' => 'Create applications', 'edit' => 'Edit applications',
            'change_status' => 'Change application status',
            'override_status' => 'Override the application status transition rules',
            'delete' => 'Cancel applications', 'export' => 'Export applications',
        ],

        'interviews' => [
            'view' => 'View interviews', 'create' => 'Schedule interviews', 'edit' => 'Edit interviews',
            'record_outcome' => 'Record interview outcomes', 'delete' => 'Delete interviews',
        ],

        'medical' => [
            'view' => 'View medical records', 'create' => 'Create medical records',
            'edit' => 'Edit medical records', 'delete' => 'Delete medical records',
        ],

        'visa' => [
            'view' => 'View visa applications', 'create' => 'Create visa applications',
            'edit' => 'Edit visa applications', 'change_status' => 'Change visa status',
            'override_status' => 'Override the visa status transition rules', 'delete' => 'Delete visa applications',
        ],

        'travel' => [
            'view' => 'View travel records', 'profile.manage' => 'Manage travel profiles',
            'tickets.manage' => 'Manage flight bookings', 'departure.manage' => 'Manage departure records',
            'placement.manage' => 'Manage placements',
        ],

        'tours' => [
            'packages.view' => 'View tour packages', 'packages.create' => 'Create tour packages',
            'packages.edit' => 'Edit tour packages', 'packages.delete' => 'Delete tour packages',
            'packages.publish' => 'Publish tour packages',
            'bookings.view' => 'View tour bookings', 'bookings.create' => 'Create tour bookings',
            'bookings.edit' => 'Edit tour bookings', 'bookings.change_status' => 'Change tour booking status',
            'bookings.delete' => 'Cancel tour bookings', 'bookings.export' => 'Export tour bookings',
        ],

        'invoices' => [
            'view' => 'View invoices', 'create' => 'Create invoices', 'edit' => 'Edit draft invoices',
            'void' => 'Void invoices', 'export' => 'Export invoices',
        ],

        'payments' => [
            'view' => 'View payments', 'create' => 'Record payments', 'edit' => 'Edit payment metadata',
            'reverse' => 'Reverse payments',
        ],

        'allocations' => [
            'manage' => 'Allocate payments to invoices',
        ],

        'refunds' => [
            'view' => 'View refunds', 'create' => 'Request refunds', 'approve' => 'Approve refunds',
            'reject' => 'Reject refunds', 'mark_paid' => 'Mark refunds as paid',
        ],

        'receipts' => [
            'view' => 'View receipts', 'issue' => 'Issue receipts',
        ],

        'reports' => [
            'view' => 'View operational reports', 'finance.view' => 'View finance reports',
            'export' => 'Export reports',
        ],

        'communication' => [
            'view' => 'View communication logs', 'log' => 'Log communications',
        ],

        'tasks' => [
            'view' => 'View tasks', 'view_all' => 'View tasks across all branches',
            'create' => 'Create tasks', 'edit' => 'Edit tasks', 'complete' => 'Complete tasks',
            'delete' => 'Delete tasks', 'assign' => 'Assign tasks',
        ],

        'imports' => [
            'run' => 'Run CSV imports',
        ],

        'exports' => [
            'run' => 'Run CSV exports',
        ],

        'public_enquiries' => [
            'view' => 'View public enquiries', 'convert' => 'Convert a public enquiry to a lead',
        ],

        'users' => [
            'view' => 'View users', 'manage' => 'Create / edit / deactivate users',
        ],

        'roles' => [
            'manage' => 'Manage roles and the permission matrix',
        ],

        'settings' => [
            'view' => 'View settings', 'manage' => 'Change settings',
        ],

        'audit' => [
            'view' => 'View the audit log',
        ],
    ],

    // ---------------------------------------------------------------------
    // Role → permission matrix. `module.*` expands to every action in that
    // module. `super_admin` is handled specially (implicit full access) but is
    // still granted every permission explicitly for transparency.
    // ---------------------------------------------------------------------
    'matrix' => [

        'super_admin' => ['*'],

        'admin' => [
            '*',
            '!roles.manage',   // only super_admin edits the permission matrix
        ],

        'manager' => [
            'system.health', 'dashboard.view', 'search.global', 'notifications.view',
            'leads.*', '!leads.view_all',
            'followups.*', 'persons.view', 'persons.merge',
            'candidates.*', '!candidates.view_all',
            'documents.*',
            'employers.*', '!employers.view_all',
            'jobs.*',
            'applications.*', '!applications.view_all',
            'interviews.*', 'medical.*', 'visa.*', 'travel.*',
            'tours.*',
            'invoices.*', 'payments.*', 'allocations.manage', 'refunds.*', 'receipts.*',
            'reports.view', 'reports.finance.view', 'reports.export',
            'communication.*', 'tasks.*', 'imports.run', 'exports.run',
            'public_enquiries.*', 'users.view', 'settings.view', 'audit.view',
        ],

        'counselor' => [
            'dashboard.view', 'search.global', 'notifications.view',
            'leads.view', 'leads.create', 'leads.edit', 'leads.assign', 'leads.convert',
            'leads.import', 'leads.export',
            'followups.view', 'followups.create', 'followups.edit', 'followups.complete',
            'persons.view',
            'candidates.view', 'candidates.create', 'candidates.edit',
            'candidates.education.manage', 'candidates.experience.manage',
            'candidates.skills.manage', 'candidates.preferences.manage', 'candidates.passport.manage',
            'documents.view', 'documents.upload', 'documents.download',
            'jobs.view', 'applications.view', 'applications.create',
            'interviews.view', 'medical.view', 'visa.view', 'travel.view',
            'communication.view', 'communication.log',
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.complete',
            'reports.view', 'public_enquiries.view', 'public_enquiries.convert',
        ],

        'recruitment' => [
            'dashboard.view', 'search.global', 'notifications.view',
            'leads.view', 'persons.view',
            'candidates.view', 'documents.view', 'documents.download',
            'employers.*', '!employers.view_all',
            'jobs.*',
            'applications.view', 'applications.create', 'applications.edit', 'applications.change_status',
            'interviews.*',
            'medical.view', 'visa.view', 'travel.view',
            'communication.view', 'communication.log',
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.complete',
            'imports.run', 'exports.run', 'reports.view',
            'public_enquiries.view', 'public_enquiries.convert',
        ],

        'documentation' => [
            'dashboard.view', 'search.global', 'notifications.view',
            'leads.view', 'persons.view', 'candidates.view',
            'documents.*',
            'applications.view', 'medical.view', 'visa.view',
            'communication.view', 'communication.log',
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.complete',
            'reports.view',
        ],

        'visa' => [
            'dashboard.view', 'search.global', 'notifications.view',
            'persons.view', 'candidates.view', 'documents.view', 'documents.upload', 'documents.download',
            'applications.view',
            'medical.*',
            'visa.view', 'visa.create', 'visa.edit', 'visa.change_status',
            'travel.*',
            'communication.view', 'communication.log',
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.complete',
            'reports.view',
        ],

        'accounts' => [
            'dashboard.view', 'search.global', 'notifications.view',
            'persons.view', 'leads.view', 'candidates.view', 'employers.view', 'documents.view', 'documents.download',
            'applications.view',
            'invoices.*', 'payments.*', 'allocations.manage',
            'refunds.view', 'refunds.create', 'refunds.mark_paid',
            'receipts.*',
            'reports.view', 'reports.finance.view', 'reports.export',
            'communication.view', 'communication.log',
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.complete',
        ],

        'travel' => [
            'dashboard.view', 'search.global', 'notifications.view',
            'persons.view',
            'tours.*',
            'invoices.view', 'invoices.create', 'invoices.edit',
            'payments.view', 'payments.create', 'allocations.manage', 'receipts.view', 'receipts.issue',
            'communication.view', 'communication.log',
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.complete',
            'reports.view', 'public_enquiries.view', 'public_enquiries.convert',
        ],

        'read_only' => [
            'dashboard.view', 'search.global', 'notifications.view',
            'leads.view', 'persons.view', 'candidates.view', 'documents.view',
            'employers.view', 'jobs.view', 'applications.view', 'interviews.view',
            'medical.view', 'visa.view', 'travel.view',
            'tours.packages.view', 'tours.bookings.view',
            'invoices.view', 'payments.view', 'refunds.view', 'receipts.view',
            'reports.view', 'communication.view', 'tasks.view',
            'public_enquiries.view',
        ],
    ],
];
