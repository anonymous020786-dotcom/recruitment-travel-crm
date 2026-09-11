<?php

declare(strict_types=1);

/**
 * Drains export_jobs (status 'pending'): generates the CSV, marks the job
 * completed with an expiry, and notifies the requester. One job's failure
 * never loses the whole run — each is caught and marked 'failed' individually.
 * Batch size is config('cron.jobs.process-exports.batch').
 */

use App\Repositories\ExportRepository;
use App\Services\LeadExportService;
use App\Support\Application;
use App\Support\CronRunner;
use App\Support\Logger;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

exit($app->get(CronRunner::class)->run('process-exports', 280, function (callable $progress) use ($app): int {
    $batchSize = (int) $app->config()->get('cron.jobs.process-exports.batch', 5);
    $jobs = $app->get(ExportRepository::class)->claimPending($batchSize);
    if ($jobs === []) {
        return 0;
    }

    $service = $app->get(LeadExportService::class);
    $repo = $app->get(ExportRepository::class);
    $logger = $app->get(Logger::class);
    $processed = 0;

    foreach ($jobs as $job) {
        try {
            $service->process($job);
            $processed++;
        } catch (\Throwable $e) {
            $logger->error('export job {id} failed: {message}', ['id' => $job['id'], 'message' => $e->getMessage(), 'exception' => $e]);
            $repo->markFailed((int) $job['id']);
        }
        $progress(1);
    }

    return $processed;
}));
