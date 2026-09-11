<?php

declare(strict_types=1);

use App\Support\Env;

/**
 * Shared-hosting-safe limits for CSV import/export. Import is processed
 * synchronously within the request (bounded by max_rows so it never runs long
 * enough to risk a shared-host execution-time limit); export is queued
 * (`export_jobs`) and drained by cron/process-exports.php, matching the
 * pattern used for outbound mail.
 */
return [
    'leads' => [
        'import' => [
            'max_rows'   => Env::int('LEAD_IMPORT_MAX_ROWS', 500),
            'max_kb'     => Env::int('LEAD_IMPORT_MAX_KB', 2048),
            'disk_path'  => 'storage/imports',
        ],
        'export' => [
            'max_rows'       => Env::int('LEAD_EXPORT_MAX_ROWS', 50000),
            'disk_path'      => 'storage/exports',
            'retention_days' => Env::int('EXPORT_RETENTION_DAYS', 7),
        ],
    ],
];
