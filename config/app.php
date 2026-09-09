<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'name'     => Env::get('APP_NAME', 'Recruitment & Travel CRM'),
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', 'http://localhost'), '/'),

    // Display timezone for the business. Storage stays UTC everywhere.
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
    'locale'   => Env::get('APP_LOCALE', 'en'),
    'fallback_locale' => 'en',
    'supported_locales' => ['en', 'hi', 'ar'],

    // 32-byte key, "base64:...." — used for CSRF/token/cookie signing (HMAC).
    'key'      => Env::get('APP_KEY', ''),

    'maintenance' => [
        // Also toggled at runtime via settings.maintenance_mode (DB).
        'enabled' => Env::bool('APP_MAINTENANCE', false),
        'bypass_roles' => ['super_admin'],
    ],

    'trusted_proxies' => Env::list('TRUSTED_PROXIES'),
];
