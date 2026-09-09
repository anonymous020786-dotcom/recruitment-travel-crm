<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use App\Support\Application;
use Closure;

/**
 * `auth` — requires an authenticated, active, non-locked user. Web requests are
 * redirected to the login route (with the intended URL stashed); API / JSON
 * requests get a 401.
 */
final class Authenticate implements Middleware
{
    public function __construct(
        private readonly Application $app,
        private readonly Auth $auth,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->check()) {
            $this->app->instance(\App\Models\User::class, $this->auth->user());

            return $next($request);
        }

        if ($request->wantsJson()) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $session = $request->attribute('session');
        if ($session instanceof Session && $request->isMethod('GET')) {
            $session->flash('_intended_url', $request->path());
        }

        $loginRoute = (string) $this->app->config()->get('auth.login_route', 'login');

        return Response::redirect(app(\App\Http\Router::class)->route($loginRoute));
    }
}
