<?php

declare(strict_types=1);

/**
 * Daily report (07:00): yesterday's activity, per branch to that branch's managers and organisation-wide to the
 * super admins. Run-date guarded — each (day, audience) is claimed before it is sent, so a re-run never
 * emails a digest twice. Quiet days are stored, not emailed.
 */

use App\Services\DailyReportService;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

return CronRunner::finish($app->get(CronRunner::class)->run('daily-report', 600, function (callable $progress) use ($app): int {
    $r = $app->get(DailyReportService::class)->run(gmdate('Y-m-d', strtotime('-1 day')));
    $progress($r['reports']);

    return $r['reports'];
}));
