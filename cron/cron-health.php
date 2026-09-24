<?php

declare(strict_types=1);

/**
 * Cron watchdog (every 15 min): looks at every job's latest run in the ledger and tells the administrators
 * about any that failed, are stuck (started, never finished) or are late (schedule fired, job never started).
 * One notification per job per state per day. It can only notice what runs — if the cron line itself is
 * dead, this job is late too and the admin screen (/admin/cron) is where that shows.
 */

use App\Services\CronHealthService;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

return CronRunner::finish($app->get(CronRunner::class)->run('cron-health', 200, function (callable $progress) use ($app): int {
    $sent = $app->get(CronHealthService::class)->alert(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $progress($sent);

    return $sent;
}));
