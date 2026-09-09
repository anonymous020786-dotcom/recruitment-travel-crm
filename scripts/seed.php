<?php

declare(strict_types=1);

/**
 * Seed runner.
 *
 *   php scripts/seed.php                       run DatabaseSeeder (all reference data)
 *   php scripts/seed.php --class=CountriesSeeder   run one seeder
 *   php scripts/seed.php --force                  allow in APP_ENV=production
 *
 * Seeders are idempotent. Production seeding is limited to reference data
 * (countries, statuses, permissions, ...) — never demo or financial data.
 */

use App\Support\Application;
use App\Support\Db;
use Database\Seeders\DatabaseSeeder;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$class = null;
$force = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif (str_starts_with($arg, '--class=')) {
        $class = substr($arg, 8);
    }
}

if ($app->environment() === 'production' && !$force) {
    fwrite(STDERR, "  Refusing to seed in production without --force.\n");
    exit(1);
}

$db = $app->get(Db::class);

$fqcn = $class === null
    ? DatabaseSeeder::class
    : (str_contains($class, '\\') ? $class : "Database\\Seeders\\{$class}");

if (!class_exists($fqcn)) {
    fwrite(STDERR, "  Seeder not found: {$fqcn}\n");
    exit(1);
}

fwrite(STDOUT, "\n  Seeding: {$fqcn}\n  " . str_repeat('-', 50) . "\n");

try {
    $seeder = new $fqcn($db, $app);
    $db->transaction(static fn () => $seeder->run());
} catch (Throwable $e) {
    fwrite(STDERR, "\n  Seeding failed: {$e->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, "  " . str_repeat('-', 50) . "\n  Done.\n\n");
