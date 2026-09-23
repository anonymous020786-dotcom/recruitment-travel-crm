<?php

declare(strict_types=1);

/**
 * Daily visa sweep: reminds the owner and the branch's visa team as an approved visa enters the
 * 180 / 90 / 30-day windows (config cron.reminder_windows.visa), then flips approved visas whose expiry
 * date has passed to 'expired'. Idempotent: reminders are deduped per visa + window + recipient and the
 * flip only matches status = 'approved'.
 */

use App\Services\ExpiryService;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

exit($app->get(CronRunner::class)->run('visa-expiry', 900, function (callable $progress) use ($app): int {
    $service = $app->get(ExpiryService::class);
    $today = gmdate('Y-m-d');
    $reminded = $service->remindVisas($today);
    $expired = $service->expireVisas($today);

    return $reminded + $expired;
}));
