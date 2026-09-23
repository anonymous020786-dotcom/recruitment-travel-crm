<?php

declare(strict_types=1);

/**
 * Daily passport sweep: reminds the candidate's counselor as a passport enters the 180 / 90 / 30-day
 * windows (config cron.reminder_windows.passport), or once it has expired, for candidates who still have
 * a live application. Deduped per passport + window + recipient.
 */

use App\Services\ExpiryService;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

exit($app->get(CronRunner::class)->run('passport-expiry', 900, function (callable $progress) use ($app): int {
    $service = $app->get(ExpiryService::class);
    $today = gmdate('Y-m-d');
    return $service->remindPassports($today);
}));
