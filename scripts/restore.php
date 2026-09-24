<?php

declare(strict_types=1);

/**
 * Restore a database dump made by scripts/backup.php.
 *
 *   php scripts/restore.php --file=storage/private/backups/db-20260924-040000.sql.gz --into=crm_restore
 *   php scripts/restore.php --file=… --into=<the live database> --overwrite-live
 *
 * --into          the database to restore INTO (must already exist; tables in it are replaced)
 * --overwrite-live  required when --into is the application's own database — this destroys current data
 *
 * The file is verified (whole gzip, footer totals) before anything is touched. Follow docs/BACKUP-RESTORE.md for the
 * full disaster procedure (maintenance mode, documents, .env, smoke test).
 */

use App\Support\Application;
use App\Support\DbRestore;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$opts = getopt('', ['file:', 'into:', 'overwrite-live']);
$file = (string) ($opts['file'] ?? '');
$into = (string) ($opts['into'] ?? '');
if ($file === '' || $into === '') {
    fwrite(STDERR, "Usage: php scripts/restore.php --file=<db-*.sql.gz> --into=<database> [--overwrite-live]\n");
    exit(2);
}
if (!is_file($file)) {
    $file = $app->basePath($file);
}

$config = (array) $app->config()->get('database.connections.mysql', []);
$live = (string) ($config['database'] ?? '');
$target = ['database' => $into] + $config;

try {
    $r = (new DbRestore($target))->restore($file, $live, isset($opts['overwrite-live']));
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("Restored into %s: %d statements, %d tables, %d rows.\n", $into, $r['statements'], $r['tables'], $r['rows']);
echo "Next: php scripts/migrate.php --status (schema must match the code), then smoke-test.\n";
