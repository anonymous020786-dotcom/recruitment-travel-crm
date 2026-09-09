<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Auth\AuthService;
use App\Controllers\Controller;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Validators\Validator;

final class PasswordResetController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function showRequestForm(): Response
    {
        return view_response('auth.forgot-password');
    }

    public function sendResetLink(Request $request): Response
    {
        $data = $request->only(['email']);

        $validator = Validator::make($data, ['email' => 'required|email|max:180']);
        if ($validator->fails()) {
            return redirect_with_errors($validator->errors(), $data, '/forgot-password');
        }

        $this->authService->sendResetLink((string) $data['email'], $request);

        flash('status', 'If that email address is in our system, a password reset link is on its way.');

        return Response::redirect('/forgot-password');
    }

    public function showResetForm(Request $request, string $token): Response
    {
        return view_response('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): Response
    {
        $data = $request->only(['token', 'email', 'password', 'password_confirmation']);

        $validator = Validator::make($data, [
            'token'    => 'required|string',
            'email'    => 'required|email|max:180',
            'password' => 'required|string|min:10|max:200|confirmed',
        ]);

        if ($validator->fails()) {
            return redirect_with_errors(
                $validator->errors(),
                $data,
                '/reset-password/' . rawurlencode((string) ($data['token'] ?? '')) . '?email=' . rawurlencode((string) ($data['email'] ?? '')),
            );
        }

        try {
            $this->authService->resetPassword(
                (string) $data['token'],
                (string) $data['email'],
                (string) $data['password'],
            );
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $data, '/forgot-password');
        }

        flash('status', 'Your password has been reset. You can now sign in.');

        return Response::redirect('/login');
    }
}
