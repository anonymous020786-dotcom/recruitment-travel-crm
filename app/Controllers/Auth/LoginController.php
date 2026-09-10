<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Auth\AuthService;
use App\Auth\RememberMe;
use App\Auth\TrustedDevice;
use App\Controllers\Controller;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Session\Session;
use App\Validators\Validator;

final class LoginController extends Controller
{
    public function __construct(
        private readonly Auth $auth,
        private readonly AuthService $authService,
        private readonly Router $router,
        private readonly RememberMe $remember,
        private readonly TrustedDevice $trustedDevice,
    ) {
    }

    public function show(): Response
    {
        return view_response('auth.login');
    }

    public function store(Request $request): Response
    {
        $data = $request->only(['email', 'password']);

        $validator = Validator::make($data, [
            'email'    => 'required|email|max:180',
            'password' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return redirect_with_errors($validator->errors(), $data, '/login');
        }

        try {
            $user = $this->authService->attempt((string) $data['email'], (string) $data['password'], $request);
        } catch (ValidationException $e) {
            // The service's generic "credentials do not match" -> banner, not a field error.
            return redirect_with_errors(['form' => $e->errors()['email'] ?? [$e->first() ?? 'Unable to sign in.']], $data, '/login');
        } catch (HttpException $e) {
            return redirect_with_errors(['form' => [$e->getMessage()]], $data, '/login');
        }

        $twoFactor = app(\App\Auth\TwoFactor::class);
        $session = $request->attribute('session');

        // Password verified. If the account has 2FA and this device is not
        // trusted, hand off to the challenge instead of completing the login.
        if ($session instanceof Session
            && $twoFactor->enabledFor($user)
            && !$this->trustedDevice->isTrusted($user, $request)) {
            $session->put('_2fa_pending', [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'trust' => $request->boolean('trust_device'),
                'at' => time(),
            ]);
            if ($user->twoFactorMethod === 'email') {
                $twoFactor->sendEmailCode($user, 'login_2fa', $request);
            }

            return Response::redirect('/two-factor');
        }

        $this->auth->login($user);

        $session = $request->attribute('session');
        $intended = $session instanceof Session ? $session->pull('_intended_url') : null;
        $target = is_string($intended) && str_starts_with($intended, '/')
            ? $intended
            : $this->router->route((string) config('auth.home_route', 'dashboard'));

        $response = Response::redirect($target);

        if ($request->boolean('remember')) {
            $c = $this->remember->issue($user, $request);
            $response->withCookie($c['name'], $c['value'], $c['options']);
        }
        if ($request->boolean('trust_device')) {
            $c = $this->trustedDevice->trust($user, $request);
            $response->withCookie($c['name'], $c['value'], $c['options']);
        }

        return $response;
    }

    public function destroy(Request $request): Response
    {
        $this->remember->forget($request);
        $this->auth->logout();

        return Response::redirect($this->router->route((string) config('auth.login_route', 'login')))
            ->withCookie(...array_values($this->remember->forgetSpec($request)));
    }
}
