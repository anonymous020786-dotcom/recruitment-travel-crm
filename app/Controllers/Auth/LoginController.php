<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Auth\AuthService;
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
            return redirect_with_errors($e->errors(), $data, '/login');
        } catch (HttpException $e) {
            return redirect_with_errors(['email' => [$e->getMessage()]], $data, '/login');
        }

        $this->auth->login($user);

        $session = $request->attribute('session');
        $intended = $session instanceof Session ? $session->pull('_intended_url') : null;
        $target = is_string($intended) && str_starts_with($intended, '/')
            ? $intended
            : $this->router->route((string) config('auth.home_route', 'dashboard'));

        return Response::redirect($target);
    }

    public function destroy(): Response
    {
        $this->auth->logout();

        return Response::redirect($this->router->route((string) config('auth.login_route', 'login')));
    }
}
