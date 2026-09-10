<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use App\Support\Application;
use Closure;

/**
 * CSRF protection for state-changing requests (POST/PUT/PATCH/DELETE and any
 * non-idempotent verb). Requires BOTH:
 *   1. a valid per-session token (in `_token` field or `X-CSRF-Token` header)
 *   2. a same-origin Origin/Referer header (when either is present)
 *
 * GET/HEAD/OPTIONS are exempt. Route names in config('security.csrf.except')
 * are exempt (e.g. third-party webhooks that use their own signature).
 */
final class VerifyCsrf implements Middleware
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::READ_METHODS, true) || $this->isExcept($request)) {
            return $next($request);
        }

        $session = $request->attribute('session');
        $this->assertToken($request, $session instanceof Session ? $session : null);

        if ((bool) $this->app->config()->get('security.csrf.check_origin', true)) {
            $this->assertSameOrigin($request);
        }

        return $next($request);
    }

    private function assertToken(Request $request, ?Session $session): void
    {
        $field = (string) $this->app->config()->get('security.csrf.field', '_token');
        $header = (string) $this->app->config()->get('security.csrf.header', 'X-CSRF-Token');

        $provided = (string) ($request->input($field) ?? $request->header($header) ?? '');
        if ($provided === '') {
            throw HttpException::pageExpired('The form has expired. Please refresh the page and try again.');
        }

        // Stateless signed token (public / session-free pages): "s:<token>".
        if (str_starts_with($provided, 's:')) {
            if (!$this->app->get(\App\Support\Signer::class)->verifyTimedToken(substr($provided, 2))) {
                throw HttpException::pageExpired('The form has expired. Please refresh the page and try again.');
            }

            return;
        }

        if ($session === null || !hash_equals($session->token(), $provided)) {
            throw HttpException::pageExpired('The form has expired. Please refresh the page and try again.');
        }
    }

    private function assertSameOrigin(Request $request): void
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');

        // Nothing to check (native app / curl without either header) — token alone stands.
        if ($origin === null && $referer === null) {
            return;
        }

        $appHost = parse_url((string) $this->app->config()->get('app.url', ''), PHP_URL_HOST);
        $sourceHost = parse_url((string) ($origin ?? $referer), PHP_URL_HOST);

        if ($appHost === null || $sourceHost === null || strcasecmp($appHost, $sourceHost) !== 0) {
            throw HttpException::pageExpired('The request origin could not be verified.');
        }
    }

    private function isExcept(Request $request): bool
    {
        $except = (array) $this->app->config()->get('security.csrf.except', []);
        $path = $request->path();

        foreach ($except as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote('/' . trim($pattern, '/'), '#')) . '$#';
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
