<?php

declare(strict_types=1);

/**
 * Composition root. Returns a booted Application (service container).
 * Used by public/index.php (web) and every cron/*.php + scripts/*.php (CLI).
 */

use App\Audit\AuditService;
use App\Auth\Auth;
use App\Auth\AuthService;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Domain\StatusMachine;
use App\Http\Router;
use App\Mail\Mailer;
use App\Mail\QueueMailer;
use App\Session\DatabaseSessionStore;
use App\Session\SessionStore;
use App\Support\Application;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\Env;
use App\Support\Hash;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Sequences;
use App\Support\Signer;
use App\View\View;

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

$app->singleton(Router::class, static fn (Application $app): Router => new Router($app));
$app->singleton(App\Http\Kernel::class);

$app->singleton(Signer::class, static fn (Application $app): Signer => new Signer(
    (string) $app->config()->get('app.key', ''),
));

$app->singleton(SessionStore::class, static fn (Application $app): SessionStore => new DatabaseSessionStore(
    $app->get(Db::class),
    (string) $app->config()->get('session.table', 'sessions'),
));

$app->singleton(Hash::class, static fn (Application $app): Hash => new Hash(
    (array) $app->config()->get('security.hash', []),
));

$app->singleton(RateLimiter::class, static fn (Application $app): RateLimiter => new RateLimiter(
    $app->get(Db::class),
    (string) $app->config()->get('rate_limits.table', 'rate_limits'),
));

$app->singleton(Mailer::class, static fn (Application $app): Mailer => new QueueMailer(
    $app->get(Db::class),
    $app->get(Logger::class),
    logBody: !$app->isProduction(),
));

$app->singleton(View::class, static fn (Application $app): View => new View(
    $app->basePath('resources/views'),
));

$app->singleton(App\View\Assets::class, static fn (Application $app): App\View\Assets => new App\View\Assets(
    $app->basePath('public'),
));

$app->singleton(Clock::class, static fn (Application $app): Clock => new Clock(
    (string) $app->config()->get('app.timezone', 'UTC'),
));

$app->singleton(Sequences::class, static fn (Application $app): Sequences => new Sequences($app->get(Db::class)));

$app->singleton(StatusMachine::class, static fn (Application $app): StatusMachine => new StatusMachine(
    (array) $app->config()->get('statuses', []),
));

$app->singleton(Auth::class);
$app->singleton(PermissionService::class);
$app->singleton(BranchScopeResolver::class);
$app->singleton(AuditService::class);
$app->singleton(AuthService::class);

$app->singleton(Gate::class, static function (Application $app): Gate {
    $gate = new Gate(
        $app,
        $app->get(PermissionService::class),
        $app->get(Auth::class),
    );

    // Policy registrations land with their feature phases, e.g.:
    // $gate->policy(\App\Models\Lead::class, \App\Policies\LeadPolicy::class);

    return $gate;
});

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

// ---- Error / exception handlers ------------------------------------------
(require __DIR__ . '/handlers.php')($app);

return $app;
