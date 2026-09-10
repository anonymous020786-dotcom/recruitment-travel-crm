<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use App\Support\Application;
use Closure;

/**
 * `confirm` — the current user must have completed a full password / 2FA auth
 * within the step-up window (`auth.password_confirm.timeout_minutes`, overridable
 * as `confirm:<minutes>`). A session recalled from a "remember me" cookie never
 * satisfies this on its own.
 *
 * Web requests are redirected to `/confirm-password` (the original target is
 * stashed); JSON requests get a 403 carrying `confirm_required` + `confirm_url`
 * so the front-end can send the user there.
 */
final class RequireRecentAuth implements Middleware
{
    private ?int $minutes;

    /** @param list<string> $args */
    public function __construct(
        private readonly Application $app,
        private readonly Auth $auth,
        array $args = [],
    ) {
        $this->minutes = isset($args[0]) && is_numeric($args[0]) ? (int) $args[0] : null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $window = $this->minutes
            ?? (int) $this->app->config()->get('auth.password_confirm.timeout_minutes', 15);

        if ($this->auth->authenticatedRecently($window)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::json([
                'error' => 'Please confirm your password to continue.',
                'confirm_required' => true,
                'confirm_url' => '/confirm-password',
            ], 403);
        }

        $session = $request->attribute('session');
        if ($session instanceof Session) {
            // Replay GET targets directly; for writes, send the user back where they came from.
            $target = $request->isMethod('GET') ? $request->path() : $this->refererPath($request);
            $session->put('_confirm_intended', $target);
            $session->flash('status', 'Please confirm your password to continue.');
        }

        return Response::redirect('/confirm-password');
    }

    private function refererPath(Request $request): string
    {
        $referer = (string) ($request->header('Referer') ?? '');
        $path = $referer !== '' ? (string) (parse_url($referer, PHP_URL_PATH) ?: '') : '';

        return str_starts_with($path, '/') ? $path : '/account/security';
    }
}
