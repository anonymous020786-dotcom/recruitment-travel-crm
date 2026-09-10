<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Models\User;
use App\Repositories\AuthTokenRepository;
use App\Repositories\UserRepository;
use App\Support\Application;
use App\Support\Logger;

/**
 * Persistent-login cookie. Uses the selector/validator pattern:
 *
 *   cookie = "<selector>:<validator>"
 *   stored  = selector (indexed), sha256(validator), series
 *
 * On each use the validator is rotated (selector + series kept). A known
 * selector with a wrong validator means the cookie was stolen and replayed —
 * the whole series is revoked and the event logged.
 *
 * A session recalled from this cookie is marked `_auth_via = remember`
 * (lower trust) — sensitive actions should still require a fresh password / 2FA.
 */
final class RememberMe
{
    private const COOKIE = 'crm_remember';

    public function __construct(
        private readonly Application $app,
        private readonly AuthTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly Logger $logger,
    ) {
    }

    public function cookieName(): string
    {
        return self::COOKIE;
    }

    private function lifetimeDays(): int
    {
        return (int) $this->app->config()->get('auth.remember.days', 30);
    }

    /** @return array{name:string,value:string,options:array<string,mixed>} */
    public function issue(User $user, Request $request, ?string $series = null): array
    {
        $series ??= bin2hex(random_bytes(16));           // 32 hex
        $selector = bin2hex(random_bytes(12));           // 24 hex
        $validator = bin2hex(random_bytes(32));          // 64 hex
        $expiresAt = new \DateTimeImmutable('+' . $this->lifetimeDays() . ' days');

        $this->tokens->create(
            $user->id,
            $series,
            $selector,
            hash('sha256', $validator),
            $expiresAt,
            $request->ipBinary(),
            $request->userAgent(),
        );

        return $this->cookieSpec($selector . ':' . $validator, $expiresAt->getTimestamp(), $request);
    }

    /**
     * Try to authenticate from the cookie. Returns [user, rotatedCookieSpec] on
     * success, or [null, forgetCookieSpec|null].
     *
     * @return array{0:?User,1:?array{name:string,value:string,options:array<string,mixed>}}
     */
    public function recall(Request $request): array
    {
        $raw = (string) $request->cookie(self::COOKIE, '');
        if (!str_contains($raw, ':')) {
            return [null, null];
        }

        [$selector, $validator] = explode(':', $raw, 2);
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            return [null, $this->forgetSpec($request)];
        }

        $record = $this->tokens->findBySelector($selector);
        if ($record === null) {
            return [null, $this->forgetSpec($request)];
        }

        if (!hash_equals($record['validator_hash'], hash('sha256', $validator))) {
            // Theft: someone replayed an old cookie. Kill the whole series.
            $this->tokens->deleteBySeries($record['series']);
            $this->logger->warning('remember-me token theft detected — series revoked', [
                'user_id' => $record['user_id'], 'series' => $record['series'],
            ]);

            return [null, $this->forgetSpec($request)];
        }

        $user = $this->users->findActiveById($record['user_id']);
        if ($user === null) {
            $this->tokens->deleteById($record['id']);

            return [null, $this->forgetSpec($request)];
        }

        // Rotate the validator, keep selector + series.
        $newValidator = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+' . $this->lifetimeDays() . ' days');
        $this->tokens->rotate($record['id'], hash('sha256', $newValidator), $expiresAt);

        return [
            $user,
            $this->cookieSpec($selector . ':' . $newValidator, $expiresAt->getTimestamp(), $request),
        ];
    }

    /** Revoke this browser's series (normal logout). */
    public function forget(Request $request): void
    {
        $raw = (string) $request->cookie(self::COOKIE, '');
        if (!str_contains($raw, ':')) {
            return;
        }
        [$selector] = explode(':', $raw, 2);
        $record = $this->tokens->findBySelector($selector);
        if ($record !== null) {
            $this->tokens->deleteBySeries($record['series']);
        }
    }

    public function revokeAll(int $userId): void
    {
        $this->tokens->deleteForUser($userId);
    }

    /** @return array{name:string,value:string,options:array<string,mixed>} */
    public function forgetSpec(Request $request): array
    {
        return $this->cookieSpec('', time() - 3600, $request);
    }

    /** @return array{name:string,value:string,options:array<string,mixed>} */
    private function cookieSpec(string $value, int $expires, Request $request): array
    {
        return [
            'name' => self::COOKIE,
            'value' => $value,
            'options' => [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => (bool) $this->app->config()->get('session.secure', true),
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
    }
}
