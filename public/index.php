<?php

declare(strict_types=1);

/**
 * Front controller. Every web request is rewritten here by public/.htaccess.
 *
 *   bootstrap -> capture Request -> load routes -> dispatch through middleware
 *   -> Controller -> Response -> send. Any Throwable is rendered by the
 *   exception Handler (safe in production, detailed only outside it).
 */

use App\Exceptions\Handler;
use App\Http\Request;
use App\Http\Router;
use App\Support\Application;

// When served by the PHP built-in server (`php -S host:port public/index.php`),
// let it serve real files in public/ directly. On Apache this never matches
// because mod_rewrite serves existing files before reaching PHP.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . '/' . ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '', '/');
    if ($file !== __FILE__ && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$request = Request::capture((array) $app->config()->get('app.trusted_proxies', []));
$app->instance(Request::class, $request);

/** @var Router $router */
$router = $app->get(Router::class);

(require $app->basePath('routes/web.php'))($router);
(require $app->basePath('routes/api.php'))($router);
$router->finalizeNames();

try {
    $response = $router->dispatch($request);
} catch (Throwable $e) {
    /** @var Handler $handler */
    $handler = $app->get(Handler::class);
    $handler->report($e);
    $response = $handler->render($request, $e);
}

$response->send(withBody: $request->realMethod() !== 'HEAD');
