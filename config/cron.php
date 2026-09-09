<?php

declare(strict_types=1);

use App\Support\Env;

return [
    // Required as argv[1] for every cron script. Scripts also refuse non-CLI SAPI.
    'secret' => Env::get('CRON_SECRET', ''),

    'lock' => [
        'table'          => 'cron_locks',
        'default_ttl_seconds' => 900,
    ],

    'runs_table' => 'cron_runs',

    // Job registry. `schedule` is advisory metadata for docs + the single-entry
    // dispatcher fallback (cron/dispatch.php) on plans with only one cron slot.
    'jobs' => [
        'followups'            => ['script' => 'cron/followups.php',            'schedule' => '*/15 * * * *', 'ttl' => 600,  'batch' => 500],
        'document-expiry'      => ['script' => 'cron/document-expiry.php',      'schedule' => '10 2 * * *',   'ttl' => 900,  'batch' => 1000],
        'passport-expiry'      => ['script' => 'cron/passport-expiry.php',      'schedule' => '20 2 * * *',   'ttl' => 900,  'batch' => 1000],
        'visa-expiry'          => ['script' => 'cron/visa-expiry.php',          'schedule' => '30 2 * * *',   'ttl' => 900,  'batch' => 1000],
        'interview-reminders'  => ['script' => 'cron/interview-reminders.php',  'schedule' => '0 * * * *',    'ttl' => 600,  'batch' => 500],
        'payment-reminders'    => ['script' => 'cron/payment-reminders.php',    'schedule' => '0 9 * * *',    'ttl' => 900,  'batch' => 1000],
        'daily-report'         => ['script' => 'cron/daily-report.php',         'schedule' => '0 7 * * *',    'ttl' => 600,  'batch' => 0],
        'process-email-queue'  => ['script' => 'cron/process-email-queue.php',  'schedule' => '*/5 * * * *',  'ttl' => 240,  'batch' => 30],
        'process-exports'      => ['script' => 'cron/process-exports.php',      'schedule' => '*/5 * * * *',  'ttl' => 280,  'batch' => 5],
        'dashboard-cache'      => ['script' => 'cron/dashboard-cache.php',      'schedule' => '*/10 * * * *', 'ttl' => 200,  'batch' => 0],
        'integrity-check'      => ['script' => 'cron/integrity-check.php',      'schedule' => '40 3 * * *',   'ttl' => 900,  'batch' => 0],
        'cleanup'              => ['script' => 'cron/cleanup.php',              'schedule' => '0 3 * * *',    'ttl' => 900,  'batch' => 0],
    ],

    // Notification reminder windows (days before expiry).
    'reminder_windows' => [
        'passport' => [180, 90, 30],
        'visa'     => [180, 90, 30],
        'document' => [30, 15, 7, 1],
        'invoice'  => [0], // on/after due date
    ],
];
