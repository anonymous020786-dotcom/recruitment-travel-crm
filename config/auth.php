<?php

declare(strict_types=1);

return [
    // Session key that holds the authenticated user id.
    'session_key' => '_auth_user_id',
    'session_meta_key' => '_auth_meta', // login time, ip, ua hash

    'login_route'  => 'login',
    'home_route'   => 'dashboard',

    // Password reset tokens.
    'passwords' => [
        'table'          => 'password_resets',
        'expire_minutes' => 60,
        'throttle_minutes' => 2,   // min gap between reset requests for one account
    ],

    // Roles that always bypass branch scoping (organisation-wide visibility).
    // Individual users may also carry users.is_org_wide = 1.
    'org_wide_roles' => ['super_admin', 'admin'],

    // System roles that cannot be deleted or renamed via the UI.
    'system_roles' => [
        'super_admin', 'admin', 'manager', 'counselor', 'recruitment',
        'documentation', 'visa', 'accounts', 'travel', 'read_only',
    ],

    // Soft UA binding: if the stored UA hash stops matching, force re-auth.
    'bind_user_agent' => true,
];
