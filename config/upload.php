<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'disk' => Env::get('UPLOAD_DISK', 'private'),

    'disks' => [
        'private' => [
            'root'   => 'storage/private',
            'public' => false,
        ],
        'exports' => [
            'root'   => 'storage/exports',
            'public' => false,
        ],
    ],

    // App-side hard cap (keep php.ini upload_max_filesize / post_max_size aligned).
    'max_kb' => Env::int('UPLOAD_MAX_KB', 12288),

    // Global allowlists. Per-document-type overrides live in document_types.
    'allowed_mime' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ],
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],

    // Magic-byte signatures checked against the finfo-detected type.
    'signatures' => [
        'application/pdf' => ["%PDF-"],
        'image/jpeg'      => ["\xFF\xD8\xFF"],
        'image/png'       => ["\x89PNG\x0D\x0A\x1A\x0A"],
        'image/webp'      => ["RIFF"], // + 'WEBP' at offset 8, verified in code
    ],

    // Extensions that are always rejected regardless of anything else.
    'blocked_extensions' => [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'pht',
        'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com',
        'htaccess', 'htpasswd', 'ini', 'js', 'mjs', 'html', 'htm', 'svg',
        'jsp', 'asp', 'aspx',
    ],

    // Re-encode raster images on ingest to strip metadata / embedded payloads.
    'reencode_images' => true,
    'max_image_pixels' => 6000 * 6000,

    'storage_name' => 'ulid',    // random, no user input in the path
    'path_shard'   => 'ym+2',    // storage/private/documents/<yy>/<mm>/<2-char>/<ulid>.<ext>
];
