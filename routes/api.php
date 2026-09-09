<?php

declare(strict_types=1);

use App\Http\Response;
use App\Http\Router;

/**
 * API routes. All JSON. Authenticated via session (same cookie) with CSRF on
 * cookie-auth mutations; token auth is a later addition behind the same guard.
 */
return static function (Router $router): void {

    $router->group(['prefix' => 'api', 'middleware' => ['api'], 'name' => 'api.'], static function (Router $r): void {
        $r->get('/ping', static fn () => Response::json(['pong' => true, 'time' => gmdate('c')]))->name('ping');
    });
};
