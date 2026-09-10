<?php

declare(strict_types=1);

use App\Support\Env;

$appUrl = rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/');
$host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';

return [
    // The Relying Party ID — a registrable domain suffix of the page origin.
    // Defaults to the APP_URL host; override in prod if you serve the CRM from
    // a subdomain but want keys to work across the apex.
    'rp_id'   => Env::get('WEBAUTHN_RP_ID', $host),
    'rp_name' => Env::get('WEBAUTHN_RP_NAME', Env::get('APP_NAME', 'Recruitment CRM')),

    // Exact origins the browser is allowed to report in clientDataJSON.
    // Scheme + host + optional port, no trailing slash.
    'origins' => Env::list('WEBAUTHN_ORIGINS') ?: [$appUrl],

    'timeout_ms'            => Env::int('WEBAUTHN_TIMEOUT_MS', 60000),
    'challenge_ttl_seconds' => Env::int('WEBAUTHN_CHALLENGE_TTL', 300),

    // 'required' | 'preferred' | 'discouraged'. Passwordless sign-in always
    // forces 'required' regardless of this setting.
    'user_verification' => Env::get('WEBAUTHN_USER_VERIFICATION', 'preferred'),

    // Allow a passkey to satisfy a passwordless sign-in (no password prompt).
    'passwordless' => Env::bool('WEBAUTHN_PASSWORDLESS', true),
];
