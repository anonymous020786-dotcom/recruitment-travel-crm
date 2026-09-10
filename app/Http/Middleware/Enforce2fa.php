<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\LoginHistoryRepository;
use App\Support\Application;
use Closure;

/**
 * `enforce2fa` — for users whose role mandates a second factor
 * (`auth.two_factor.required_roles`) but who have not set one up yet.
 *
 * They get a grace period of `auth.two_factor.grace_logins` sign-ins during
 * which the app stays usable (with a nag shown via the `twofa_grace_left`
 * request attribute). After that, every page except the enrolment / sign-out
 * routes redirects to the 2FA setup screen until a factor is configured.
 *
 * "Sign-ins" are counted from `login_history` — server-side, so clearing
 * cookies does not reset the countdown. The fast path (no required roles, or
 * the user already has 2FA) resolves nothing extra.
 */
final class Enforce2fa implements Middleware
{
    /** Path prefixes that stay reachable while enrolment is outstanding. */
    private const ALLOW = [
        '/account/two-factor',
        '/account/recovery-codes',
        '/account/passkeys',
        '/confirm-password',
        '/logout',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Auth $auth,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return $next($request);
        }

        $required = (array) $this->app->config()->get('auth.two_factor.required_roles', []);
        if ($required === []
            || !in_array($user->roleName, $required, true)
            || $user->twoFactorEnabled
            || $this->auth->loggedInVia() === 'passkey') {
            return $next($request);
        }

        $grace = max(0, (int) $this->app->config()->get('auth.two_factor.grace_logins', 3));
        $used = $this->app->get(LoginHistoryRepository::class)->countForUser($user->id);
        $left = $grace - $used;

        if ($left > 0) {
            $request->setAttribute('twofa_grace_left', $left);

            return $next($request);
        }

        $request->setAttribute('twofa_grace_left', 0);

        if ($this->isAllowed($request->path())) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::json([
                'error' => 'Two-factor authentication is required for your role. Set it up to continue.',
                'twofa_required' => true,
                'setup_url' => '/account/two-factor',
            ], 403);
        }

        $request->attribute('session')?->flash(
            'error_toast',
            'Two-factor authentication is required for your role. Set it up now to continue.',
        );

        return Response::redirect('/account/two-factor');
    }

    private function isAllowed(string $path): bool
    {
        foreach (self::ALLOW as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
