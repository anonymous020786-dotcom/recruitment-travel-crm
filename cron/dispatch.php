<?php

declare(strict_types=1);

/**
 * Single-entry cron dispatcher — for hosting plans with only one cron slot.
 *
 * Schedule it every 5 minutes:  php /path/to/cron/dispatch.php <CRON_SECRET>
 *
 * Runs whichever jobs in config/cron.php are due (see App\Support\CronDispatcher), one after another, inside
 * `cron.dispatch_budget_seconds`. Jobs are included in this process; each still takes its own lock and writes
 * its own cron_runs row, so this can coexist with dedicated cron lines. Exit code: 0 all ran (or nothing due),
 * 1 at least one job failed.
 */

use App\Support\Application;
use App\Support\CronDispatcher;
use App\Support\Logger;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';
$GLOBALS['cron_app'] = $app;
define('CRON_IN_PROCESS', true);

$config = $app->config();
$jobs = (array) $config->get('cron.jobs', []);

$dispatcher = new CronDispatcher(
    $app->get(\App\Support\Db::class),
    $jobs,
    static function (string $name, array $def) use ($app): int {
        $script = $app->basePath((string) ($def['script'] ?? ''));
        if (!is_file($script)) {
            $app->get(Logger::class)->warning('cron dispatch: script for {job} does not exist yet ({script})', ['job' => $name, 'script' => $def['script'] ?? '?']);

            return CronDispatcher::MISSING;
        }

        return (int) (require $script);
    },
    $app->get(Logger::class),
);

$results = $dispatcher->run(new DateTimeImmutable('now', new DateTimeZone('UTC')), (int) $config->get('cron.dispatch_budget_seconds', 240));

foreach ($results as $job => $outcome) {
    echo str_pad($job, 24) . $outcome . PHP_EOL;
}
if ($results === []) {
    echo 'nothing due' . PHP_EOL;
}

exit(in_array('failed', $results, true) ? 1 : 0);
