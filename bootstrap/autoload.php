<?php

declare(strict_types=1);

/**
 * Zero-dependency PSR-4 autoloader.
 *
 * The application must run on shared hosting where `composer` may be unavailable.
 * This registers the `App\` -> `app/` mapping directly. If a Composer autoloader
 * is present (dev machines, or `composer install --no-dev` on capable hosts) it
 * is loaded first for dev tooling; this loader still covers the `App\` namespace.
 */

$root = dirname(__DIR__);

$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}

$map = [
    'App\\'              => $root . '/app/',
    'Database\\Seeders\\' => $root . '/database/seeders/',
];

spl_autoload_register(static function (string $class) use ($map): void {
    foreach ($map as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
}, prepend: false);
