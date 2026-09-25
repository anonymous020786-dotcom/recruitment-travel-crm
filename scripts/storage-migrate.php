<?php

declare(strict_types=1);

/**
 * Move documents from this server's disk into the configured bucket (Amazon S3 or Cloudflare R2), in verified batches.
 *
 *   php scripts/storage-migrate.php --dry-run        show what would move, change nothing
 *   php scripts/storage-migrate.php                  move everything, 25 at a time (repeatable and resumable)
 *   php scripts/storage-migrate.php --batch=100 --max=500
 *
 * The bucket must already be configured and chosen under Admin → Integrations → Document storage. Each document is uploaded with
 * its SHA-256, read back with a HEAD (size checked), and only then is its record switched and the local copy deleted. Anything that
 * fails stays where it is and is counted; run it again to retry. The same work is available in Admin → Storage, 25 at a time.
 */

use App\Storage\DocumentMigrator;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__) . '/bootstrap/app.php';

$dry = in_array('--dry-run', $argv, true);
$batch = 25;
$max = PHP_INT_MAX;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--batch=(\d{1,3})$/', $arg, $m)) {
        $batch = max(1, min(200, (int) $m[1]));
    } elseif (preg_match('/^--max=(\d{1,9})$/', $arg, $m)) {
        $max = (int) $m[1];
    } elseif ($arg !== '--dry-run') {
        fwrite(STDERR, "Unknown option {$arg}\n");
        exit(2);
    }
}

$migrator = $app->get(DocumentMigrator::class);
$before = $migrator->pending();
echo ($dry ? 'DRY RUN — ' : '') . number_format($before['pending']) . ' document(s) (' . number_format($before['bytes'] / 1048576, 1) . " MB) on the server disk.\n";

$moved = $failed = $missing = 0;
do {
    $r = $migrator->moveBatch($batch, $dry);
    if (isset($r['error'])) {
        fwrite(STDERR, $r['error'] . "\n");
        exit(1);
    }
    $moved += $r['moved'];
    $failed += $r['failed'];
    $missing += $r['missing'];
    echo sprintf("  batch: moved %d, failed %d, missing %d — %d left\n", $r['moved'], $r['failed'], $r['missing'], $r['remaining']);
    // stop when a batch made no progress (only failures/missing remain), on a dry run (it never changes state), or at --max
} while (!$dry && $r['moved'] > 0 && $r['remaining'] > 0 && $moved < $max);

echo sprintf("\nDone: %d %s, %d failed, %d missing on disk.\n", $moved, $dry ? 'would move' : 'moved', $failed, $missing);
exit($failed > 0 ? 1 : 0);
