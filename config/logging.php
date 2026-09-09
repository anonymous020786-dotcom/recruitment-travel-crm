<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'path' => 'storage/logs',

    // Lower threshold in non-production for easier debugging.
    'min_level' => Env::get('APP_ENV', 'production') === 'production' ? 'info' : 'debug',

    // Keys scrubbed from any logged context before it hits disk.
    'redact_keys' => [
        'password', 'password_hash', 'current_password', 'new_password',
        'token', 'secret', 'api_key', 'authorization', 'cookie',
        'passport_number', 'aadhaar', 'pan', 'card', 'cvv',
    ],

    'retention_days' => 60,
];
