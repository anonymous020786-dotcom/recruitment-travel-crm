<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Auth\RememberMe;
use App\Auth\TrustedDevice;
use App\Auth\TwoFactor;
use App\Controllers\Controller;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Session\Session;

/**
 * The second step of a 2FA login. Reached only when a session carries a
 * `_2fa_pending` block set by LoginController after the password check.
 */
final class TwoFactorChallengeController extends Controller
{
    private const PENDING_TTL = 600; // 10 minutes to complete the challenge

    public function __construct(
        private readonly Auth $auth,
        private readonly TwoFactor $twoFactor,
        private readonly UserRepository $users,
        private readonly RememberMe $remember,
        private readonly TrustedDevice $trustedDevice,
    ) {
    }

    public function show(Request $request): Response
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return Response::redirect('/login');
        }
        $user = $this->users->findActiveById($pending['user_id']);
        if ($user === null) {
            return $this->abandon($request);
        }

        return view_response('auth.two-factor', [
            'method' => $user->twoFactorMethod,
            'email'  => $this->maskEmail($user->email),
        ]);
    }

    public function verify(Request $request): Response
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return Response::redirect('/login');
        }
        $user = $this->users->findActiveById($pending['user_id']);
        if ($user === null) {
            return $this->abandon($request);
        }

        $mode = (string) $request->input('mode', 'totp');
        $code = (string) $request->input('code', '');
        $ok = false;

        try {
            $ok = match ($mode) {
                'recovery' => $this->twoFactor->verifyRecoveryCode($user, $code),
                'email'    => $this->twoFactor->verifyEmailCode($user, 'login_2fa', $code),
                default    => $this->twoFactor->verifyTotp($user, $code),
            };
        } catch (HttpException $e) {
            return redirect_with_errors(['form' => [$e->getMessage()]], [], '/two-factor');
        }

        if (!$ok) {
            return redirect_with_errors(['code' => ['That code is not correct.']], [], '/two-factor');
        }

        // Success — complete the login.
        $session = $request->attribute('session');
        $session?->forget('_2fa_pending');

        $this->auth->login($user); // full password-grade auth
        app(\App\Auth\LoginAlerts::class)->afterLogin($user, $request, 'password');

        $response = Response::redirect(
            is_string($intended = $session?->pull('_intended_url')) && str_starts_with($intended, '/')
                ? $intended
                : $this->router()->route((string) config('auth.home_route', 'dashboard')),
        );

        if (!empty($pending['remember'])) {
            $c = $this->remember->issue($user, $request);
            $response->withCookie($c['name'], $c['value'], $c['options']);
        }
        if (!empty($pending['trust'])) {
            $c = $this->trustedDevice->trust($user, $request);
            $response->withCookie($c['name'], $c['value'], $c['options']);
        }

        return $response;
    }

    public function resendEmail(Request $request): Response
    {
        $pending = $this->pending($request);
        $user = $pending !== null ? $this->users->findActiveById($pending['user_id']) : null;
        if ($user !== null) {
            $this->twoFactor->sendEmailCode($user, 'login_2fa', $request);
            flash('status', 'A new code has been sent to your email.');
        }

        return Response::redirect($pending === null ? '/login' : '/two-factor');
    }

    // ---- internals -------------------------------------------------

    /** @return array{user_id:int,remember:bool,trust:bool,at:int}|null */
    private function pending(Request $request): ?array
    {
        $session = $request->attribute('session');
        $pending = $session instanceof Session ? $session->get('_2fa_pending') : null;

        if (!is_array($pending) || !isset($pending['user_id'], $pending['at'])
            || (time() - (int) $pending['at']) > self::PENDING_TTL) {
            $session?->forget('_2fa_pending');

            return null;
        }

        return [
            'user_id' => (int) $pending['user_id'],
            'remember' => (bool) ($pending['remember'] ?? false),
            'trust' => (bool) ($pending['trust'] ?? false),
            'at' => (int) $pending['at'],
        ];
    }

    private function abandon(Request $request): Response
    {
        $request->attribute('session')?->forget('_2fa_pending');

        return Response::redirect('/login');
    }

    private function router(): \App\Http\Router
    {
        return app(\App\Http\Router::class);
    }

    private function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $maskedUser = mb_strlen($user) <= 2 ? $user[0] . '…' : $user[0] . str_repeat('•', max(1, mb_strlen($user) - 2)) . mb_substr($user, -1);

        return "{$maskedUser}@{$domain}";
    }
}
