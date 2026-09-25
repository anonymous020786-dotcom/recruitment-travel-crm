<?php

declare(strict_types=1);

/**
 * Composition root. Returns a booted Application (service container).
 * Used by public/index.php (web) and every cron/*.php + scripts/*.php (CLI).
 */

use App\Support\Application;
use App\Support\Config;
use App\Support\Db;
use App\Support\Env;
use App\Support\Logger;

require __DIR__ . '/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

$root = dirname(__DIR__);

// ---- Environment --------------------------------------------------------------
Env::load($root . '/.env');

// ---- Application container ---------------------------------------------------
$app = new Application($root);

$app->singleton(Config::class, static function () use ($root): Config {
    $cache = $root . '/bootstrap/cache/config.php';
    if (is_file($cache)) {
        /** @psalm-suppress UnresolvableInclude */
        $cached = require $cache;
        if (is_array($cached)) {
            return new Config($cached);
        }
    }

    return new Config($root . '/config');
});

$app->singleton(Logger::class, static function (Application $app): Logger {
    $cfg = $app->config();
    return new Logger(
        directory: $app->basePath((string) $cfg->get('logging.path', 'storage/logs')),
        minLevel: (string) $cfg->get('logging.min_level', 'info'),
    );
});

$app->singleton(Db::class, static function (Application $app): Db {
    $cfg = $app->config();
    $name = (string) $cfg->get('database.default', 'mysql');

    return new Db(
        config: (array) $cfg->get("database.connections.{$name}", []),
        logger: $app->get(Logger::class),
    );
});

// ---- Service bindings (shared with the test harness) ---------------------
(require __DIR__ . '/services.php')($app);

// ---- PHP runtime posture ---------------------------------------------------
$config = $app->config();

error_reporting(E_ALL);

if ($app->isDebug()) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
ini_set('log_errors', '1');

// Harden session/cookie defaults early (the session layer in Step 1.5 refines this).
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', (string) $config->get('session.same_site', 'Lax'));
if ((bool) $config->get('session.secure', true)) {
    ini_set('session.cookie_secure', '1');
}

$app->boot();

// ---- Credentials saved in Admin → Integrations override config/.env (Turnstile, mail, analytics…). One small query;
// silently skipped before the table exists (fresh install) or when the database is unreachable.
if (\PHP_SAPI !== 'cli' || !\defined('SKIP_CREDENTIAL_OVERLAY')) {
    try {
        $app->get(\App\Integrations\Credentials::class)->applyToConfig();
    } catch (\Throwable) {
    }
    // Rate limits, the two-factor policy and the IP-rules flag saved in Admin → Security (same rule: skipped when unavailable).
    try {
        $app->get(\App\Security\SecurityPolicy::class)->applyToConfig();
    } catch (\Throwable) {
    }
}

// ---- Error / exception handlers ------------------------------------------
(require __DIR__ . '/handlers.php')($app);

return $app;
