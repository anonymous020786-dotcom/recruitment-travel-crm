<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'default' => Env::get('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => Env::get('DB_HOST', '127.0.0.1'),
            'port'      => Env::int('DB_PORT', 3306),
            'database'  => Env::get('DB_NAME', ''),
            'username'  => Env::get('DB_USER', ''),
            'password'  => Env::get('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',

            // Session is always UTC; the app converts for display.
            'timezone'  => '+00:00',

            // PDO options. Real prepared statements, exceptions, assoc rows.
            'options' => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_PERSISTENT         => false,
            ],
        ],
    ],

    // Runner reads these; migrations live as ordered .sql files.
    'migrations' => [
        'path'  => 'database/migrations',
        'table' => 'schema_migrations',
    ],
];
