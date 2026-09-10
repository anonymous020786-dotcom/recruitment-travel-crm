<?php

declare(strict_types=1);

/**
 * Shared prologue for every cron/*.php script.
 *
 *   - refuses to run over HTTP
 *   - requires the CRON_SECRET as argv[1] when one is configured
 *   - returns the booted Application
 *
 * Usage in a job:
 *   $app = require __DIR__ . '/_bootstrap.php';
 *   exit($app->get(CronRunner::class)->run('job-name', 300, function ($progress) { ... }));
 */

use App\Support\Application;

if (\PHP_SAPI !== 'cli' && \PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$configuredSecret = (string) $app->config()->get('cron.secret', '');
if ($configuredSecret !== '') {
    $provided = $argv[1] ?? '';
    if (!hash_equals($configuredSecret, (string) $provided)) {
        fwrite(STDERR, "cron: invalid or missing secret\n");
        exit(3);
    }
}

return $app;
