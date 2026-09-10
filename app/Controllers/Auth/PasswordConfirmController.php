<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\UserRepository;
use App\Session\Session;
use App\Support\Hash;

/**
 * Password-confirmation ("sudo mode") screen. Reached when the `confirm`
 * middleware finds the session has no recent full auth. A correct password
 * stamps `_authenticated_at`, reopening the step-up window, then bounces the
 * user to wherever they were headed.
 */
final class PasswordConfirmController extends Controller
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Hash $hash,
        private readonly UserRepository $users,
        private readonly Router $router,
    ) {
    }

    public function show(Request $request): Response
    {
        $window = (int) config('auth.password_confirm.timeout_minutes', 15);
        if ($this->auth->authenticatedRecently($window)) {
            return Response::redirect($this->target($request, consume: true));
        }

        return view_response('auth.confirm-password');
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $password = (string) $request->input('password', '');
        $stored = $this->users->passwordHashFor($user->id) ?? '';

        if ($password === '' || !$this->hash->verify($password, $stored)) {
            return redirect_with_errors(['password' => ['That password is incorrect.']], [], '/confirm-password');
        }

        $this->auth->recordIdentityConfirmation();
        audit()->log('password_confirmed', 'auth', 'user', $user->id, null, null, 'step-up', $user);

        return Response::redirect($this->target($request, consume: true));
    }

    private function target(Request $request, bool $consume = false): string
    {
        $session = $request->attribute('session');
        $intended = $session instanceof Session
            ? ($consume ? $session->pull('_confirm_intended') : $session->get('_confirm_intended'))
            : null;

        return is_string($intended) && str_starts_with($intended, '/') && $intended !== '/confirm-password'
            ? $intended
            : $this->router->route('account.security');
    }
}
