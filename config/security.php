<?php

declare(strict_types=1);

use App\Support\Env;

return [
    // ---- Password hashing --------------------------------------------------
    'hash' => [
        'algo' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
        'bcrypt'  => ['cost' => 12],
        'argon2id' => ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
        // Re-hash a stored hash on next successful login if params changed.
        'rehash_on_login' => true,
    ],

    // ---- Login throttling / lockout --------------------------------------
    'login' => [
        'max_attempts_per_window' => 5,
        'window_minutes'          => 15,
        'lockout_minutes'         => 15,
        'track_by'                => ['ip', 'email'],
    ],

    // ---- CSRF -----------------------------------------------------------
    'csrf' => [
        'field'        => '_token',
        'header'       => 'X-CSRF-Token',
        'token_bytes'  => 32,
        'check_origin' => true,   // verify Origin/Referer host on state-changing requests
        'except'       => [],     // route names exempt (none by default)
    ],

    // ---- Security response headers -------------------------------------
    'headers' => [
        'hsts' => [
            'enabled'            => Env::bool('HSTS_ENABLED', true),
            'max_age'            => Env::int('HSTS_MAX_AGE', 31536000),
            'include_subdomains' => true,
            'preload'            => false,
        ],
        'x_content_type_options' => 'nosniff',
        'referrer_policy'        => 'strict-origin-when-cross-origin',
        'permissions_policy'     => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'x_frame_options'        => 'DENY',           // CRM; public group overrides to SAMEORIGIN
        'cross_origin_opener_policy'   => 'same-origin',
        'cross_origin_resource_policy' => 'same-origin',
    ],

    // Content-Security-Policy. Scripts/styles use a per-request nonce; no
    // 'unsafe-inline' for scripts. Public group relaxes img/style for OG assets.
    'csp' => [
        'crm' => [
            "default-src" => ["'self'"],
            "base-uri"    => ["'self'"],
            "object-src"  => ["'none'"],
            "frame-ancestors" => ["'none'"],
            "img-src"     => ["'self'", "data:"],
            "font-src"    => ["'self'", "https://fonts.gstatic.com"],
            "style-src"   => ["'self'", "https://fonts.googleapis.com", "'nonce-{nonce}'"],
            "script-src"  => ["'self'", "'nonce-{nonce}'"],
            "connect-src" => ["'self'"],
            "form-action" => ["'self'"],
        ],
        'public' => [
            "default-src" => ["'self'"],
            "base-uri"    => ["'self'"],
            "object-src"  => ["'none'"],
            "frame-ancestors" => ["'self'"],
            "img-src"     => ["'self'", "data:", "https:"],
            "font-src"    => ["'self'", "https://fonts.gstatic.com"],
            "style-src"   => ["'self'", "https://fonts.googleapis.com", "'unsafe-inline'"],
            "script-src"  => ["'self'", "'nonce-{nonce}'"],
            "connect-src" => ["'self'"],
            "form-action" => ["'self'"],
        ],
    ],

    // ---- Sensitive-field masking (enforced in policy/view layer) --------
    'masking' => [
        'keep_last' => 4,
        'mask_char' => "\u{2022}",
    ],
];
