<?php

declare(strict_types=1);

/**
 * Daily medical-certificate sweep: reminds the owner and the branch's visa team as a fit certificate
 * enters the 30 / 15 / 7-day windows (config cron.reminder_windows.medical), or once it has lapsed, for
 * candidates who still have a live application. Deduped per certificate + window + recipient.
 */

use App\Services\ExpiryService;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

exit($app->get(CronRunner::class)->run('medical-expiry', 900, function (callable $progress) use ($app): int {
    $service = $app->get(ExpiryService::class);
    $today = gmdate('Y-m-d');
    return $service->remindMedical($today);
}));
