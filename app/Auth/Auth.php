<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Session\Session;
use App\Support\Application;

/**
 * Per-request authentication state, backed by the session. Holds only the user
 * id in the session; the User DTO is lazy-loaded and re-validated (active, not
 * locked, matching user-agent) on each request.
 */
class Auth
{
    private ?User $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly Application $app,
        private readonly UserRepository $users,
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): ?int
    {
        return $this->session()?->get('_auth_user_id') !== null
            ? (int) $this->session()->get('_auth_user_id')
            : null;
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $session = $this->session();
        $id = $session?->get('_auth_user_id');
        if ($session === null || $id === null) {
            return $this->user = null;
        }

        $user = $this->users->findActiveById((int) $id);

        if ($user === null || $user->isLocked() || !$this->userAgentMatches($session)) {
            $this->clearSession($session);

            return $this->user = null;
        }

        return $this->user = $user;
    }

    /** @param string $via 'password' | 'passkey' | 'remember' — recalled logins are lower trust. */
    public function login(User $user, string $via = 'password'): void
    {
        $session = $this->requireSession();
        $session->regenerate(destroyOld: true);
        $session->put('_auth_user_id', $user->id);
        $session->put('_auth_via', $via);
        // A password check and a passwordless passkey (user-verified) are both
        // full-strength auth; a remember-me recall is not.
        if ($via === 'password' || $via === 'passkey') {
            $session->put('_authenticated_at', time());
        }
        $session->put('_auth_meta', [
            'ua'  => $this->userAgentHash(),
            'ip'  => $this->app->bound(Request::class) ? $this->app->get(Request::class)->ip() : null,
            'at'  => time(),
        ]);
        $session->regenerateToken();

        $this->user = $user;
        $this->resolved = true;
    }

    public function loggedInVia(): string
    {
        return (string) ($this->session()?->get('_auth_via') ?? 'password');
    }

    /** True if a fresh password/2FA auth happened within the given window. */
    public function authenticatedRecently(int $withinMinutes = 30): bool
    {
        $at = (int) ($this->session()?->get('_authenticated_at') ?? 0);

        return $at > 0 && (time() - $at) <= $withinMinutes * 60;
    }

    /**
     * Record that the user just re-proved their identity (a password
     * confirmation / step-up check). Refreshes the "recent auth" window.
     */
    public function recordIdentityConfirmation(): void
    {
        $this->session()?->put('_authenticated_at', time());
    }

    public function loginUsingId(int $id): ?User
    {
        $user = $this->users->findActiveById($id);
        if ($user !== null) {
            $this->login($user);
        }

        return $user;
    }

    public function logout(): void
    {
        $session = $this->session();
        if ($session !== null) {
            $session->invalidate();
        }
        $this->user = null;
        $this->resolved = true;
    }

    // ---- internals -------------------------------------------------

    private function session(): ?Session
    {
        return $this->app->bound(Session::class) ? $this->app->get(Session::class) : null;
    }

    private function requireSession(): Session
    {
        $session = $this->session();
        if ($session === null) {
            throw new \RuntimeException('No session — Auth::login() requires the session middleware.');
        }

        return $session;
    }

    private function clearSession(Session $session): void
    {
        $session->forget(['_auth_user_id', '_auth_meta']);
    }

    private function userAgentMatches(Session $session): bool
    {
        if (!(bool) $this->app->config()->get('auth.bind_user_agent', true)) {
            return true;
        }
        $meta = $session->get('_auth_meta');
        $stored = is_array($meta) ? ($meta['ua'] ?? null) : null;

        return $stored === null || hash_equals((string) $stored, $this->userAgentHash());
    }

    private function userAgentHash(): string
    {
        $ua = $this->app->bound(Request::class) ? $this->app->get(Request::class)->userAgent() : '';

        return hash('sha256', $ua);
    }
}
