<?php

declare(strict_types=1);

/**
 * CRM sidebar navigation. Each item is shown only if the current user holds
 * `permission` (checked with can()). `match` is the path prefix used to mark the
 * item active.
 */
return [
    ['label' => 'Dashboard',    'icon' => 'dashboard',    'path' => '/dashboard',      'permission' => 'dashboard.view'],
    ['label' => 'Leads',        'icon' => 'leads',        'path' => '/leads',          'permission' => 'leads.view'],
    ['label' => 'Follow-ups',   'icon' => 'followups',    'path' => '/followups',      'permission' => 'followups.view'],
    ['label' => 'Candidates',   'icon' => 'candidates',   'path' => '/candidates',     'permission' => 'candidates.view'],
    ['label' => 'Employers',    'icon' => 'employers',    'path' => '/employers',      'permission' => 'employers.view'],
    ['label' => 'Jobs',         'icon' => 'jobs',         'path' => '/jobs',           'permission' => 'jobs.view'],
    ['label' => 'Applications', 'icon' => 'applications', 'path' => '/applications',   'permission' => 'applications.view'],
    ['label' => 'Interviews',   'icon' => 'interviews',   'path' => '/interviews',     'permission' => 'interviews.view'],
    ['label' => 'Medical',      'icon' => 'medical',      'path' => '/medical',        'permission' => 'medical.view'],
    ['label' => 'Visa',         'icon' => 'visa',         'path' => '/visa',           'permission' => 'visa.view'],
    ['label' => 'Travel',       'icon' => 'travel',       'path' => '/travel',         'permission' => 'travel.view'],
    ['label' => 'Tours',        'icon' => 'tours',        'path' => '/tours/packages', 'permission' => 'tours.packages.view'],
    ['label' => 'Bookings',     'icon' => 'tours',        'path' => '/tours/bookings', 'permission' => 'tours.bookings.view'],
    ['label' => 'Invoices',     'icon' => 'invoices',     'path' => '/invoices',       'permission' => 'invoices.view'],
    ['label' => 'Payments',     'icon' => 'payments',     'path' => '/payments',       'permission' => 'payments.view'],
    ['label' => 'Reports',      'icon' => 'reports',      'path' => '/reports',        'permission' => 'reports.view'],
    ['label' => 'Tasks',        'icon' => 'tasks',        'path' => '/tasks',          'permission' => 'tasks.view'],
    ['label' => 'Admin',        'icon' => 'admin',        'path' => '/admin/users',    'permission' => 'users.view'],
];
