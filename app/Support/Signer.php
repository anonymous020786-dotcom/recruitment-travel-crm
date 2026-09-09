<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HMAC-SHA256 signing with the application key. Used for stateless tokens
 * (public-form CSRF later), signed URLs, and tamper-evident cookies.
 *
 * The key comes from config('app.key') as "base64:....". A missing key is a
 * hard error — the app must not run without it in any environment that signs.
 */
final class Signer
{
    private string $key;

    public function __construct(string $appKey)
    {
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $this->key = $decoded !== false ? $decoded : '';
        } else {
            $this->key = $appKey;
        }

        if (strlen($this->key) < 16) {
            throw new \RuntimeException('APP_KEY is missing or too short. Generate one with: php -r "echo \'base64:\'.base64_encode(random_bytes(32));"');
        }
    }

    public function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }

    /** value|signature */
    public function sign(string $value): string
    {
        return $value . '|' . $this->hmac($value);
    }

    /** Returns the original value if the signature is valid, else null. */
    public function unsign(string $signed): ?string
    {
        $pos = strrpos($signed, '|');
        if ($pos === false) {
            return null;
        }

        $value = substr($signed, 0, $pos);
        $signature = substr($signed, $pos + 1);

        return hash_equals($this->hmac($value), $signature) ? $value : null;
    }

    /**
     * Time-limited token: random nonce + expiry, signed. Verifiable without any
     * server-side state.
     */
    public function timedToken(int $ttlSeconds = 7200): string
    {
        $payload = bin2hex(random_bytes(16)) . ':' . (time() + $ttlSeconds);

        return rtrim(strtr(base64_encode($this->sign($payload)), '+/', '-_'), '=');
    }

    public function verifyTimedToken(string $token): bool
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }

        $payload = $this->unsign($decoded);
        if ($payload === null) {
            return false;
        }

        $parts = explode(':', $payload);
        $expiry = (int) end($parts);

        return $expiry >= time();
    }
}
