<?php

declare(strict_types=1);

/** Send queued emails (email_log). Idempotent, lock-guarded. */

use App\Mail\MailQueue;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

exit($app->get(CronRunner::class)->run('process-email-queue', 280, function () use ($app): int {
    return $app->get(MailQueue::class)->process();
}));
