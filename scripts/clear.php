<?php

declare(strict_types=1);

/** Remove generated caches (config cache, compiled views if any). */

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$targets = [
    $root . '/bootstrap/cache/config.php',
];

$removed = 0;
foreach ($targets as $file) {
    if (is_file($file)) {
        unlink($file);
        fwrite(STDOUT, "  removed {$file}\n");
        $removed++;
    }
}

fwrite(STDOUT, $removed === 0 ? "  Nothing to clear.\n" : "  Cleared {$removed} cache file(s).\n");
