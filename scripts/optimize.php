<?php

declare(strict_types=1);

/**
 * Production optimisation: cache the resolved config tree to a single file and
 * warm the Composer classmap if present. Run on every deploy AFTER .env is in
 * place. Reverse with scripts/clear.php.
 *
 *   php scripts/optimize.php
 */

use App\Support\Application;
use App\Support\Config;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

// Build config straight from the source dir (ignore any existing cache).
require $root . '/bootstrap/autoload.php';
require $root . '/app/Support/helpers.php';
App\Support\Env::load($root . '/.env');

$fresh = new Config($root . '/config');
$cacheFile = $root . '/bootstrap/cache/config.php';
$fresh->dumpTo($cacheFile);

// Sanity check the file parses and returns an array.
$loaded = require $cacheFile;
if (!is_array($loaded) || $loaded === []) {
    fwrite(STDERR, "  Config cache looks wrong — removing.\n");
    @unlink($cacheFile);
    exit(1);
}

fwrite(STDOUT, "\n  Cached config: {$cacheFile} (" . count($loaded) . " groups)\n");

$composer = $root . '/vendor/composer/autoload_classmap.php';
if (is_file($root . '/composer.json') && trim(shell_exec('composer --version 2>/dev/null') ?? '') !== '') {
    passthru('composer dump-autoload --optimize --no-dev --working-dir=' . escapeshellarg($root));
} else {
    fwrite(STDOUT, "  (composer not available — using the built-in autoloader)\n");
}

fwrite(STDOUT, "  Done.\n\n");
