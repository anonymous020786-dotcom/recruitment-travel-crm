<?php

declare(strict_types=1);

/**
 * Take a backup now: a verified database dump plus uploaded documents, into storage/private/backups.
 *
 *   php scripts/backup.php [--db-only] [--full]
 *
 * --db-only  skip the documents archive
 * --full     force a full documents archive (otherwise weekly full + daily incrementals)
 *
 * The daily cron job (cron/backup.php) does the same. Copy the files off-site afterwards — see docs/BACKUP-RESTORE.md.
 */

use App\Support\Application;
use App\Support\BackupManager;
use App\Support\Logger;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$opts = getopt('', ['db-only', 'full']);

try {
    $r = (new BackupManager(
        $app->basePath('storage/private/backups'),
        (array) $app->config()->get('database.connections.mysql', []),
        $app->basePath('storage/private/documents'),
        $app->get(Logger::class),
    ))->run(!isset($opts['db-only']), isset($opts['full']));
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("Database  %s  (%d tables, %d rows, %s)\n", $r['db']['file'], $r['db']['tables'], $r['db']['rows'], number_format($r['db']['bytes'] / 1024, 1) . ' KB');
echo $r['files'] !== null
    ? sprintf("Documents %s  (%d files, %s, %s)\n", $r['files']['file'], $r['files']['files'], number_format($r['files']['bytes'] / 1024, 1) . ' KB', $r['files']['kind'])
    : "Documents nothing to archive\n";
if ($r['pruned'] !== []) {
    echo 'Pruned    ' . implode(', ', $r['pruned']) . "\n";
}
echo "Done. Copy the new file(s) off-site.\n";
