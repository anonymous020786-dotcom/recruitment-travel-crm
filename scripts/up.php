<?php

declare(strict_types=1);

/** Bring the application out of maintenance mode. */

use App\Support\Application;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$file = $app->storagePath('framework/down');

if (is_file($file)) {
    unlink($file);
    fwrite(STDOUT, "\n  Maintenance mode OFF.\n\n");
} else {
    fwrite(STDOUT, "\n  Application was not in maintenance mode.\n\n");
}
