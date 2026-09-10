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

    // Step-up: sensitive actions (managing 2FA, passkeys, revoking every
    // session) require a full password/2FA auth within this window. A session
    // recalled from a "remember me" cookie has no fresh auth, so it is always
    // asked to confirm. Enforced by the RequireRecentAuth middleware (`confirm`).
    'password_confirm' => [
        'timeout_minutes' => 15,
    ],

    // Persistent login ("remember me").
    'remember' => [
        'days' => 30,
        // Actions considered "sensitive" require a fresh full auth (password/2FA)
        // even inside a session recalled from the remember cookie. Enforced by
        // the RequireRecentAuth middleware (added with the 2FA step).
        'step_up_after_minutes' => 30,
    ],

    // "Trust this device" — skips the 2FA prompt on this device.
    'trusted_device' => [
        'days' => 30,
    ],

    // Two-factor authentication.
    'two_factor' => [
        'issuer' => \App\Support\Env::get('TWO_FACTOR_ISSUER', \App\Support\Env::get('APP_NAME', 'Recruitment CRM')),
        // Roles for which a second factor is mandatory (enforced with a grace period).
        'required_roles' => \App\Support\Env::list('TWO_FACTOR_REQUIRED_ROLES'),
        'grace_logins'   => 3,
        // Email fallback code.
        'email_ttl_minutes'    => 10,
        'email_resend_seconds' => 60,
        // How long a passed 2FA / password check counts as "recent" for step-up.
        'step_up_minutes' => 15,
    ],
];
