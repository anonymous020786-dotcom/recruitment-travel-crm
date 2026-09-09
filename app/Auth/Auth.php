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
final class Auth
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

    public function login(User $user): void
    {
        $session = $this->requireSession();
        $session->regenerate(destroyOld: true);
        $session->put('_auth_user_id', $user->id);
        $session->put('_auth_meta', [
            'ua'  => $this->userAgentHash(),
            'ip'  => $this->app->bound(Request::class) ? $this->app->get(Request::class)->ip() : null,
            'at'  => time(),
        ]);
        $session->regenerateToken();

        $this->user = $user;
        $this->resolved = true;
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
