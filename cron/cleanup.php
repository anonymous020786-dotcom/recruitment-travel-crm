<?php

declare(strict_types=1);

/**
 * Nightly housekeeping: prune expired/stale rows so the small support tables
 * stay small on shared hosting. Safe to run repeatedly.
 */

use App\Mail\MailQueue;
use App\Repositories\AuthTokenRepository;
use App\Repositories\ExportRepository;
use App\Repositories\ImportRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\TrustedDeviceRepository;
use App\Repositories\TwoFactorRepository;
use App\Support\Application;
use App\Support\CronRunner;
use App\Support\Db;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

exit($app->get(CronRunner::class)->run('cleanup', 600, function (callable $progress) use ($app): void {
    $db = $app->get(Db::class);
    $sessionTable = (string) $app->config()->get('session.table', 'sessions');
    $sessionLifetime = (int) $app->config()->get('session.lifetime_minutes', 480) * 60;

    $progress($db->affectingStatement(
        "DELETE FROM `{$sessionTable}` WHERE last_activity < :cut",
        ['cut' => time() - $sessionLifetime - 86400],
    ));
    $progress($db->affectingStatement(
        "DELETE FROM rate_limits WHERE window_started < (UTC_TIMESTAMP() - INTERVAL 2 DAY)",
    ));
    $progress($app->get(AuthTokenRepository::class)->pruneExpired());
    $progress($app->get(TrustedDeviceRepository::class)->pruneExpired());
    $progress($app->get(TwoFactorRepository::class)->pruneExpired());
    $progress($app->get(PasswordResetRepository::class)->pruneExpired());
    $progress($app->get(LoginAttemptRepository::class)->pruneOlderThan(45));
    $progress($app->get(MailQueue::class)->prune(30));

    $progress($db->affectingStatement(
        "DELETE FROM cron_runs WHERE started_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)",
    ));
    // Dashboard snapshots are only ever reused for seconds; a day-old one is dead weight.
    $progress($db->affectingStatement(
        "DELETE FROM settings WHERE key_name LIKE 'dash:%' AND updated_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)",
    ));
    $progress($db->affectingStatement(
        "DELETE FROM cron_locks WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)",
    ));
    $progress($db->affectingStatement(
        "DELETE FROM login_history WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 180 DAY)",
    ));
    $progress($db->affectingStatement(
        "DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < (UTC_TIMESTAMP() - INTERVAL 60 DAY)",
    ));

    // Import batches (with their staged/uploaded CSV + any error report) older
    // than 30 days; export files past their retention window.
    foreach ($app->get(ImportRepository::class)->pruneOlderThan(30) as $path) {
        @unlink($app->basePath($path));
        $progress(1);
    }
    foreach ($app->get(ExportRepository::class)->pruneExpired() as $path) {
        @unlink($app->basePath($path));
        $progress(1);
    }
}));
