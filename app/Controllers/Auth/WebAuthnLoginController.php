<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Auth\LoginAlerts;
use App\Auth\WebAuthn\WebAuthnException;
use App\Auth\WebAuthn\WebAuthnService;
use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\UserRepository;
use App\Session\Session;

/**
 * Passwordless sign-in with a passkey. A discoverable credential (resident key)
 * identifies the account; user verification on the authenticator is mandatory,
 * so the passkey alone is a two-factor-grade proof.
 */
final class WebAuthnLoginController extends Controller
{
    public function __construct(
        private readonly WebAuthnService $webauthn,
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly Router $router,
        private readonly LoginAlerts $loginAlerts,
    ) {
    }

    /** JSON: options for navigator.credentials.get() with no allowCredentials. */
    public function options(Request $request): Response
    {
        if (!$this->enabled()) {
            return $this->json(['error' => 'Passkey sign-in is not available.'], 404);
        }

        return $this->json($this->webauthn->assertionOptions($this->session($request), null));
    }

    /** JSON: verify the assertion and start a session. */
    public function verify(Request $request): Response
    {
        if (!$this->enabled()) {
            return $this->json(['error' => 'Passkey sign-in is not available.'], 404);
        }

        $session = $this->session($request);

        try {
            $result = $this->webauthn->verifyAssertion($request->json(), $session, null);
        } catch (WebAuthnException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        $user = $this->users->findActiveById($result['user_id']);
        if ($user === null) {
            return $this->json(['error' => 'This account is not available.'], 403);
        }

        $this->auth->login($user, 'passkey');
        $this->loginAlerts->afterLogin($user, $request, 'passkey');

        $intended = $session->pull('_intended_url');
        $target = is_string($intended) && str_starts_with($intended, '/')
            ? $intended
            : $this->router->route((string) config('auth.home_route', 'dashboard'));

        return $this->json(['ok' => true, 'redirect' => $target]);
    }

    private function enabled(): bool
    {
        return (bool) config('webauthn.passwordless', true);
    }

    private function session(Request $request): Session
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            abort(500, 'Session unavailable.');
        }

        return $session;
    }
}
