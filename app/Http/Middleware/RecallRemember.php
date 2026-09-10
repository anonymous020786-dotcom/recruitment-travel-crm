<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Auth\RememberMe;
use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use App\Support\Application;
use Closure;

/**
 * If there is no authenticated session but a valid "remember me" cookie is
 * present, sign the user in (marking the session `_auth_via = remember`) and
 * rotate the cookie on the way out. Runs after StartSession, before Authenticate.
 *
 * RememberMe (and its DB repositories) are resolved lazily — only when the
 * cookie is actually present — so requests without one stay DB-free.
 */
final class RecallRemember implements Middleware
{
    private const COOKIE = 'crm_remember';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attribute('session');

        $shouldTry = $session instanceof Session
            && $session->get('_auth_user_id') === null
            && $request->cookie(self::COOKIE) !== null;

        if (!$shouldTry) {
            return $next($request);
        }

        /** @var RememberMe $remember */
        $remember = $this->app->get(RememberMe::class);
        [$user, $cookie] = $remember->recall($request);

        if ($user !== null) {
            $this->app->get(Auth::class)->login($user, via: 'remember');
        }

        $response = $next($request);

        if ($cookie !== null) {
            $response->withCookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        return $response;
    }
}
