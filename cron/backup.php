<?php

declare(strict_types=1);

/**
 * Daily backup (04:00): a verified database dump plus uploaded documents (weekly full, daily incremental) into
 * storage/private/backups, then retention pruning. A failure shows on /admin/cron and alerts the administrators
 * through the cron-health job. Copy the files off-site — see docs/BACKUP-RESTORE.md.
 */

use App\Support\Application;
use App\Support\BackupManager;
use App\Support\CronRunner;
use App\Support\Logger;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

return CronRunner::finish($app->get(CronRunner::class)->run('backup', 1700, function (callable $progress) use ($app): int {
    $r = (new BackupManager(
        $app->basePath('storage/private/backups'),
        (array) $app->config()->get('database.connections.mysql', []),
        $app->basePath('storage/private/documents'),
        $app->get(Logger::class),
    ))->run();
    $progress(1 + ($r['files'] !== null ? 1 : 0));

    return 1 + ($r['files'] !== null ? 1 : 0);
}));
