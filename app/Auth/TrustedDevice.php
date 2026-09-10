<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Models\User;
use App\Repositories\TrustedDeviceRepository;
use App\Support\Application;

/**
 * "Trust this device for 30 days" — lets a device skip the 2FA prompt (never
 * the password). A random token in a cookie; only its sha256 is stored.
 */
final class TrustedDevice
{
    private const COOKIE = 'crm_device';

    public function __construct(
        private readonly Application $app,
        private readonly TrustedDeviceRepository $repo,
    ) {
    }

    public function cookieName(): string
    {
        return self::COOKIE;
    }

    private function days(): int
    {
        return (int) $this->app->config()->get('auth.trusted_device.days', 30);
    }

    /** @return array{name:string,value:string,options:array<string,mixed>} */
    public function trust(User $user, Request $request, ?string $label = null): array
    {
        $token = bin2hex(random_bytes(32));
        $until = new \DateTimeImmutable('+' . $this->days() . ' days');

        $this->repo->create(
            $user->id,
            hash('sha256', $token),
            $label ?? $this->guessLabel($request),
            hash('sha256', $request->userAgent()),
            $request->ipBinary(),
            $until,
        );

        return $this->cookieSpec($token, $until->getTimestamp());
    }

    public function isTrusted(User $user, Request $request): bool
    {
        $token = (string) $request->cookie(self::COOKIE, '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $hash = hash('sha256', $token);
        if ($this->repo->isTrusted($user->id, $hash)) {
            $this->repo->touch($hash, $request->ipBinary());

            return true;
        }

        return false;
    }

    public function forget(User $user, Request $request): void
    {
        $token = (string) $request->cookie(self::COOKIE, '');
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->repo->deleteByHash($user->id, hash('sha256', $token));
        }
    }

    public function revokeAll(int $userId): void
    {
        $this->repo->deleteForUser($userId);
    }

    /** @return array{name:string,value:string,options:array<string,mixed>} */
    public function forgetSpec(): array
    {
        return $this->cookieSpec('', time() - 3600);
    }

    private function guessLabel(Request $request): string
    {
        $ua = $request->userAgent();
        $os = match (true) {
            str_contains($ua, 'Windows')  => 'Windows',
            str_contains($ua, 'Mac OS')   => 'macOS',
            str_contains($ua, 'Android')  => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Linux')    => 'Linux',
            default => 'Unknown',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/')     => 'Edge',
            str_contains($ua, 'Chrome/')  => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/')  => 'Safari',
            default => 'browser',
        };

        return "{$browser} on {$os}";
    }

    /** @return array{name:string,value:string,options:array<string,mixed>} */
    private function cookieSpec(string $value, int $expires): array
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
