<?php

declare(strict_types=1);

use App\Support\Env;

/**
 * Third-party integrations. All optional and env-driven — an unset value simply
 * disables that feature. Analytics / chat load ONLY on the public site, never on
 * authenticated CRM pages.
 */
return [

    'whatsapp' => [
        // International format, digits only (e.g. 919812345678).
        'number'  => preg_replace('/\D/', '', (string) Env::get('INTEGRATIONS_WHATSAPP_NUMBER', '')),
        'default_text' => Env::get('INTEGRATIONS_WHATSAPP_TEXT', 'Hi, I would like to know more about your services.'),
        'button_enabled' => Env::bool('INTEGRATIONS_WHATSAPP_BUTTON', true),
    ],

    'analytics' => [
        // GA4 Measurement ID, e.g. G-XXXXXXXXXX
        'ga4_id' => trim((string) Env::get('INTEGRATIONS_GA4_ID', '')),
        // Only send analytics after the visitor accepts the cookie notice.
        'require_consent' => Env::bool('INTEGRATIONS_ANALYTICS_CONSENT', true),
    ],

    'turnstile' => [
        'site_key'   => trim((string) Env::get('INTEGRATIONS_TURNSTILE_SITE_KEY', '')),
        'secret_key' => trim((string) Env::get('INTEGRATIONS_TURNSTILE_SECRET_KEY', '')),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        // When true and keys are missing, forms still submit (dev). In production
        // with keys set, a missing/invalid token is rejected.
        'optional_when_unconfigured' => true,
    ],

    'tawk' => [
        'property_id' => trim((string) Env::get('INTEGRATIONS_TAWK_PROPERTY_ID', '')),
        'widget_id'   => trim((string) Env::get('INTEGRATIONS_TAWK_WIDGET_ID', 'default')),
    ],

    // Hosts that the PUBLIC Content-Security-Policy must allow when the matching
    // integration is enabled. Merged into config('security.csp.public') at runtime.
    'csp' => [
        'analytics' => [
            'script-src'  => ['https://www.googletagmanager.com'],
            'img-src'     => ['https://www.google-analytics.com', 'https://www.googletagmanager.com'],
            'connect-src' => ['https://www.google-analytics.com', 'https://*.analytics.google.com', 'https://www.googletagmanager.com'],
        ],
        'turnstile' => [
            'script-src' => ['https://challenges.cloudflare.com'],
            'frame-src'  => ['https://challenges.cloudflare.com'],
        ],
        'tawk' => [
            'script-src'  => ['https://embed.tawk.to', 'https://*.tawk.to'],
            'connect-src' => ['https://*.tawk.to', 'wss://*.tawk.to'],
            'img-src'     => ['https://*.tawk.to', 'https://tawk.link'],
            'style-src'   => ['https://embed.tawk.to', "'unsafe-inline'"],
            'frame-src'   => ['https://*.tawk.to'],
        ],
    ],
];
