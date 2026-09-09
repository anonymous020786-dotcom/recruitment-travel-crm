<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\Application;
use Closure;

/**
 * `guest` — keeps authenticated users away from login / password-reset pages,
 * redirecting them to the application home.
 */
final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(
        private readonly Application $app,
        private readonly Auth $auth,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->check()) {
            return Response::redirect(
                $this->app->get(Router::class)->route((string) $this->app->config()->get('auth.home_route', 'dashboard')),
            );
        }

        return $next($request);
    }
}
