<?php

declare(strict_types=1);

/**
 * Database migration runner.
 *
 *   php scripts/migrate.php                 apply all pending migrations
 *   php scripts/migrate.php --status        show applied / pending + drift
 *   php scripts/migrate.php --pretend       print the SQL that would run
 *   php scripts/migrate.php --step=1        apply only the next N migrations
 *   php scripts/migrate.php --fresh         DROP every table, then migrate  (non-prod, or --force)
 *   php scripts/migrate.php --force         allow running in APP_ENV=production
 *
 * Migrations are ordered *.sql files in database/migrations/. Filename (minus
 * .sql) is the version. Each file is recorded in schema_migrations with a
 * sha256 checksum; a changed applied file is reported as drift by --status.
 *
 * DDL auto-commits on MySQL/MariaDB, so a failed migration is not rolled back
 * automatically — the runner stops on the first failing statement and tells you
 * exactly which file/statement failed so you can fix forward.
 */

use App\Support\Application;
use App\Support\Db;
use App\Support\SqlScript;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$args = array_slice($argv, 1);
$opt = static function (string $name, mixed $default = false) use ($args): mixed {
    foreach ($args as $a) {
        if ($a === "--{$name}") {
            return true;
        }
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
};

$db = $app->get(Db::class);
$config = $app->config();
$table = (string) $config->get('database.migrations.table', 'schema_migrations');
$dir = $app->basePath((string) $config->get('database.migrations.path', 'database/migrations'));

$isProduction = $app->environment() === 'production';
$force = (bool) $opt('force');

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function fail(string $line): never
{
    fwrite(STDERR, "\n  ERROR: {$line}\n");
    exit(1);
}

// ---- Discover migration files ---------------------------------------------
$files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    fail("No migration files found in {$dir}");
}

/** @var array<string,array{version:string,path:string,checksum:string}> $migrations */
$migrations = [];
foreach ($files as $path) {
    $version = basename($path, '.sql');
    $migrations[$version] = [
        'version'  => $version,
        'path'     => $path,
        'checksum' => hash('sha256', (string) file_get_contents($path)),
    ];
}

// ---- Ensure tracking table ----------------------------------------------
try {
    $db->unprepared(
        "CREATE TABLE IF NOT EXISTS `{$table}` (
            `version`    VARCHAR(60)  NOT NULL,
            `filename`   VARCHAR(160) NOT NULL,
            `checksum`   CHAR(64)     NOT NULL,
            `batch`      INT UNSIGNED NOT NULL DEFAULT 1,
            `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`version`)
        )"
    );
} catch (Throwable $e) {
    fail('Could not create/verify the migrations table: ' . $e->getMessage());
}

/** @var array<string,array{checksum:string,batch:int,applied_at:string}> $applied */
$applied = [];
foreach ($db->select("SELECT version, checksum, batch, applied_at FROM `{$table}`") as $row) {
    $applied[(string) $row['version']] = [
        'checksum'   => (string) $row['checksum'],
        'batch'      => (int) $row['batch'],
        'applied_at' => (string) $row['applied_at'],
    ];
}

// ---- --status -----------------------------------------------------------
if ($opt('status')) {
    out();
    out('  Migration status (' . $app->environment() . ')');
    out('  ' . str_repeat('-', 64));
    foreach ($migrations as $version => $m) {
        if (!isset($applied[$version])) {
            out(sprintf('  [ ] %-45s pending', $version));
            continue;
        }
        $drift = $applied[$version]['checksum'] !== $m['checksum'] ? '  !! FILE CHANGED SINCE APPLIED' : '';
        out(sprintf('  [x] %-45s %s%s', $version, $applied[$version]['applied_at'], $drift));
    }
    foreach ($applied as $version => $_) {
        if (!isset($migrations[$version])) {
            out(sprintf('  [?] %-45s applied but file missing', $version));
        }
    }
    $pending = array_diff(array_keys($migrations), array_keys($applied));
    out('  ' . str_repeat('-', 64));
    out('  ' . count($applied) . ' applied, ' . count($pending) . ' pending');
    out();
    exit(0);
}

// ---- --fresh ----------------------------------------------------------
if ($opt('fresh')) {
    if ($isProduction && !$force) {
        fail('Refusing --fresh in production without --force.');
    }
    out('  Dropping all tables ...');
    $db->unprepared('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($db->select('SHOW TABLES') as $row) {
        $name = (string) array_values($row)[0];
        $db->unprepared("DROP TABLE IF EXISTS `{$name}`");
        out("    dropped {$name}");
    }
    $db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
    $applied = [];
    $db->unprepared(
        "CREATE TABLE IF NOT EXISTS `{$table}` (
            `version` VARCHAR(60) NOT NULL, `filename` VARCHAR(160) NOT NULL,
            `checksum` CHAR(64) NOT NULL, `batch` INT UNSIGNED NOT NULL DEFAULT 1,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`version`))"
    );
}

// ---- Apply pending ---------------------------------------------------
if ($isProduction && !$force && !$opt('pretend')) {
    fail('APP_ENV=production. Re-run with --force once you have a verified backup.');
}

$pending = array_values(array_filter(
    $migrations,
    static fn ($m) => !isset($applied[$m['version']])
));

$step = $opt('step');
if ($step !== false) {
    $pending = array_slice($pending, 0, max(1, (int) $step));
}

if ($pending === []) {
    out('  Nothing to migrate. Database is up to date.');
    exit(0);
}

$batch = (int) ($db->selectValue("SELECT COALESCE(MAX(batch), 0) FROM `{$table}`") ?? 0) + 1;
$pretend = (bool) $opt('pretend');

out();
out('  ' . ($pretend ? 'Pretending to run' : 'Running') . ' ' . count($pending) . ' migration(s), batch ' . $batch);
out('  ' . str_repeat('-', 64));

foreach ($pending as $m) {
    $sql = (string) file_get_contents($m['path']);
    $statements = SqlScript::split($sql);

    out(sprintf('  %s  (%d statements)', $m['version'], count($statements)));

    if ($pretend) {
        foreach ($statements as $s) {
            out('    ' . preg_replace('/\s+/', ' ', substr(trim($s), 0, 120)));
        }
        continue;
    }

    foreach ($statements as $i => $statement) {
        try {
            $db->unprepared($statement);
        } catch (Throwable $e) {
            fail(sprintf(
                "%s failed at statement #%d:\n  %s\n  %s",
                $m['version'],
                $i + 1,
                preg_replace('/\s+/', ' ', substr(trim($statement), 0, 200)),
                $e->getMessage(),
            ));
        }
    }

    $db->insertRow($table, [
        'version'  => $m['version'],
        'filename' => basename($m['path']),
        'checksum' => $m['checksum'],
        'batch'    => $batch,
    ]);

    out('    applied.');
}

out('  ' . str_repeat('-', 64));
out('  Done.');
out();
