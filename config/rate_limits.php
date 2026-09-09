<?php

declare(strict_types=1);

/**
 * DB-backed fixed-window rate limits. Key template `{scope}` is filled with the
 * IP, user id, or email depending on `by`. `limit` requests per `window_seconds`.
 */
return [
    'table' => 'rate_limits',

    'buckets' => [
        'login'          => ['by' => ['ip', 'email'], 'limit' => 5,   'window_seconds' => 900],
        'password_reset' => ['by' => ['ip'],          'limit' => 3,   'window_seconds' => 3600],
        'search'         => ['by' => ['user'],        'limit' => 30,  'window_seconds' => 60],
        'dashboard'      => ['by' => ['user'],        'limit' => 60,  'window_seconds' => 60],
        'write'          => ['by' => ['user'],        'limit' => 120, 'window_seconds' => 60],
        'payments.write' => ['by' => ['user'],        'limit' => 20,  'window_seconds' => 60],
        'upload'         => ['by' => ['user'],        'limit' => 30,  'window_seconds' => 600],
        'import'         => ['by' => ['user'],        'limit' => 5,   'window_seconds' => 3600],
        'export'         => ['by' => ['user'],        'limit' => 10,  'window_seconds' => 3600],
        'public_form'    => ['by' => ['ip'],          'limit' => 5,   'window_seconds' => 3600],
    ],
];
