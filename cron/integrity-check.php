<?php

declare(strict_types=1);

/**
 * Integrity check (daily, quiet hours): proves the ledger and pipelines are internally consistent — invoice
 * money against payments and refunds, statuses against money, totals against lines, receipts, polymorphic
 * links, application history, document counters. The result is stored for the admin screen and, when a check
 * fails, the administrators are notified once per check per day. Findings are reported, not thrown: the job
 * only "fails" if it could not run.
 */

use App\Services\IntegrityService;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

return CronRunner::finish($app->get(CronRunner::class)->run('integrity-check', 900, function (callable $progress) use ($app): int {
    $r = $app->get(IntegrityService::class)->run();
    $progress(count($r['findings']));

    return count($r['findings']);
}));
