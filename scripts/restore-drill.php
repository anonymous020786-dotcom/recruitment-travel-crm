<?php

declare(strict_types=1);

/**
 * Restore drill: proves the backups can actually be restored. "An untested backup is assumed broken."
 *
 *   php scripts/restore-drill.php [--file=<db-*.sql.gz>] [--into=<scratch database>] [--keep]
 *
 * Without --file it takes a fresh dump of the live database first, so the drill also proves the *current* data can
 * be dumped and reloaded. It restores into a scratch database (default `<live>_drill`, created if you may; on shared
 * hosting create an empty one in hPanel and pass --into), then checks the restored copy against what was dumped:
 * every table's row count, and the migration ledger. Never touches the live database. Exit 0 = drill passed.
 * Run it quarterly (and after any change to the backup code).
 */

use App\Support\Application;
use App\Support\BackupManager;
use App\Support\DbBackup;
use App\Support\DbRestore;
use App\Support\Logger;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$opts = getopt('', ['file::', 'into::', 'keep']);
$config = (array) $app->config()->get('database.connections.mysql', []);
$live = (string) ($config['database'] ?? '');
$into = (string) ($opts['into'] ?? ($live . '_drill'));
if ($into === $live) {
    fwrite(STDERR, "Refusing: --into must not be the live database.\n");
    exit(2);
}

$step = static function (string $msg): void { echo $msg . "\n"; };
$fail = static function (string $msg): never { fwrite(STDERR, "DRILL FAILED: {$msg}\n"); exit(1); };

// 1. the dump under test
$expected = null;
$tmp = null;
if (isset($opts['file'])) {
    $file = (string) $opts['file'];
    if (!is_file($file)) {
        $file = $app->basePath($file);
    }
    $step("Using {$file}");
} else {
    $tmp = sys_get_temp_dir() . '/drill-' . bin2hex(random_bytes(4)) . '.sql.gz';
    $t = microtime(true);
    $dump = (new DbBackup($config))->dump($tmp);
    $file = $tmp;
    $expected = $dump['per_table'];
    $step(sprintf('Dumped %s: %d tables, %d rows, %.1f MB in %.1fs', $live, $dump['tables'], $dump['rows'], $dump['bytes'] / 1048576, microtime(true) - $t));

    // Content fingerprints of the live tables, taken right after the dump. Row counts alone would not catch a value
    // that came back different (binary IPs, JSON, decimals, non-ASCII text, NULLs), a checksum does.
    $liveSums = [];
    $livePdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 3306), $live), (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (array_keys($expected) as $table) {
        $liveSums[$table] = (string) $livePdo->query('CHECKSUM TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
    }
}

try {
    // 2. verify the file on its own
    $v = DbBackup::verify($file);
    $step(sprintf('Verified: whole gzip, footer matches (%d tables, %d rows)', $v['tables'], $v['rows']));

    // 3. scratch database
    $admin = new PDO(sprintf('mysql:host=%s;port=%d', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 3306)), (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $into) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        $step("(could not create {$into}: " . $e->getMessage() . ' — it must already exist)');
    }

    // 4. restore
    $t = microtime(true);
    $r = (new DbRestore(['database' => $into] + $config))->restore($file, $live);
    $step(sprintf('Restored into %s: %d statements in %.1fs', $into, $r['statements'], microtime(true) - $t));

    // 5. compare
    $copy = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 3306), $into), (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $bad = [];
    $restoredRows = 0;
    foreach ($copy->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
        $n = (int) $copy->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        $restoredRows += $n;
        if ($expected !== null && isset($expected[$table]) && $expected[$table] !== $n) {
            $bad[] = "{$table}: dumped {$expected[$table]}, restored {$n}";
        }
    }
    if ($restoredRows !== $v['rows']) {
        $bad[] = "total rows: dump has {$v['rows']}, restored copy has {$restoredRows}";
    }
    if ($expected !== null) {
        $step(sprintf('Compared %d tables row for row', count($expected)));
        $mismatch = [];
        foreach ($liveSums as $table => $sum) {
            if ((string) $copy->query('CHECKSUM TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1] !== $sum) {
                $mismatch[] = $table;
            }
        }
        $step(sprintf('Compared content checksums of %d tables: %d differ', count($liveSums), count($mismatch)));
        if ($mismatch !== []) {
            $bad[] = 'content differs in: ' . implode(', ', $mismatch) . ' (if the site was busy during the drill, re-run it quietly)';
        }
    }
    $migrations = (int) $copy->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($migrations < 1) {
        $bad[] = 'schema_migrations is empty in the restored copy';
    }

    if (!isset($opts['keep'])) {
        try {
            $admin->exec('DROP DATABASE `' . str_replace('`', '', $into) . '`');
            $step("Dropped scratch database {$into}");
        } catch (Throwable) {
            $step("(left {$into} in place — drop it yourself)");
        }
    }

    if ($bad !== []) {
        $fail("restored copy differs from the dump:\n  - " . implode("\n  - ", $bad));
    }
} catch (Throwable $e) {
    $fail($e->getMessage());
} finally {
    if ($tmp !== null) {
        @unlink($tmp);
    }
}

echo "DRILL PASSED: the backup restores completely.\n";
