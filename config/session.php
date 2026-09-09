<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'driver'   => Env::get('SESSION_DRIVER', 'database'), // database | file

    'cookie'   => Env::get('SESSION_COOKIE', 'crm_session'),
    'path'     => '/',
    'domain'   => null,
    'secure'   => Env::bool('SESSION_SECURE', true),
    'http_only' => true,
    'same_site' => Env::get('SESSION_SAME_SITE', 'Lax'), // Lax | Strict

    // Absolute lifetime and idle timeout, both in minutes.
    'lifetime_minutes' => Env::int('SESSION_LIFETIME_MINUTES', 480),
    'idle_minutes'     => Env::int('SESSION_IDLE_MINUTES', 30),

    // Regenerate the session id at least this often (minutes) and always on
    // any privilege change (login, role change, impersonation).
    'regenerate_minutes' => 20,

    'table' => 'sessions',
    'files' => 'storage/framework/sessions',

    // Garbage-collection lottery is handled by cron/cleanup.php, not per-request.
    'gc_via_cron' => true,
];
