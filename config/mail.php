<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'driver' => Env::get('MAIL_DRIVER', 'smtp'), // smtp | log

    'smtp' => [
        'host'       => Env::get('MAIL_HOST', 'localhost'),
        'port'       => Env::int('MAIL_PORT', 587),
        'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'), // ssl | tls | none
        'username'   => Env::get('MAIL_USERNAME', ''),
        'password'   => Env::get('MAIL_PASSWORD', ''),
        'timeout'    => 15,
    ],

    'from' => [
        'address' => Env::get('MAIL_FROM_ADDRESS', 'no-reply@localhost'),
        'name'    => Env::get('MAIL_FROM_NAME', 'Recruitment & Travel CRM'),
    ],

    // Outbound mail is queued into email_log and sent by cron/process-email-queue.php.
    'queue' => [
        'table'         => 'email_log',
        'batch_size'    => 30,
        'max_attempts'  => 5,
        'retry_backoff_minutes' => [1, 5, 15, 60, 240],
    ],

    // Daily "your follow-ups" digest email (cron/followups.php). The in-app
    // reminder notification is always created regardless of this flag.
    'followup_reminders' => Env::bool('MAIL_FOLLOWUP_REMINDERS', true),

    // Morning digest of yesterday's activity (cron/daily-report.php): branch managers get their branch, super admins
    // the whole organisation. Quiet days are never emailed. The report itself is always stored in `settings`.
    'daily_report' => Env::bool('MAIL_DAILY_REPORT', true),
];
