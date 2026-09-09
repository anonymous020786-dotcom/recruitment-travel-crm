<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'default_title'       => Env::get('SEO_DEFAULT_TITLE', 'Overseas Jobs, Recruitment & Travel'),
    'title_suffix'        => ' | ' . Env::get('APP_NAME', 'Recruitment & Travel CRM'),
    'default_description' => Env::get('SEO_DEFAULT_DESCRIPTION', 'Overseas employment, recruitment and travel services.'),
    'organization_name'   => Env::get('SEO_ORG_NAME', 'Your Agency'),

    // Private areas: emitted as X-Robots-Tag AND <meta name="robots"> on CRM pages.
    'crm_robots' => 'noindex, nofollow, noarchive',

    // robots.txt disallow list for the private application.
    'robots_disallow' => [
        '/dashboard', '/leads', '/candidates', '/persons', '/applications',
        '/interviews', '/visa', '/medical', '/documents', '/employers', '/jobs/',
        '/invoices', '/payments', '/refunds', '/reports', '/exports', '/tasks',
        '/admin', '/api', '/login', '/logout', '/account', '/tours/bookings',
    ],

    'public_cache_seconds' => 300,
    'sitemap_cache_seconds' => 3600,
];
