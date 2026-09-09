<?php

declare(strict_types=1);

/**
 * Put the application into maintenance mode (503 for everyone except bypass).
 *
 *   php scripts/down.php [--retry=120] [--allow=1.2.3.4,5.6.7.8]
 *
 * Prints a bypass URL containing a one-time secret. Remove with scripts/up.php.
 */

use App\Support\Application;
use App\Support\Ulid;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$retry = 120;
$allow = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--retry=')) {
        $retry = max(1, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--allow=')) {
        $allow = array_values(array_filter(array_map('trim', explode(',', substr($arg, 8)))));
    }
}

$secret = strtolower(Ulid::generate());
$dir = $app->storagePath('framework');
if (!is_dir($dir)) {
    mkdir($dir, 0770, true);
}

file_put_contents($dir . '/down', json_encode([
    'secret' => $secret,
    'retry'  => $retry,
    'allow'  => $allow,
    'since'  => gmdate('c'),
], JSON_PRETTY_PRINT));

$url = rtrim((string) $app->config()->get('app.url', ''), '/') . '/?secret=' . $secret;

fwrite(STDOUT, "\n  Maintenance mode ON.\n  Bypass: {$url}\n  Retry-After: {$retry}s\n\n");
