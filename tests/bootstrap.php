<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap. Registers the app autoloaders plus a Tests\ -> tests/
 * mapping, without requiring the full application bootstrap (tests opt in).
 */

error_reporting(E_ALL);

$root = dirname(__DIR__);

require $root . '/bootstrap/autoload.php';

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (!defined('TEST_ROOT')) {
    define('TEST_ROOT', $root);
}
