<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RFC 6238 (TOTP) / RFC 4226 (HOTP) — authenticator-app codes. Home-grown, no
 * external dependency. HMAC-SHA1, 6 digits, 30-second period by default, with a
 * ±1 step verification window to tolerate clock skew.
 */
final class Totp
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly int $period = 30,
        private readonly int $digits = 6,
        private readonly string $algorithm = 'sha1',
    ) {
    }

    /** New base32 secret (default 160 bits, RFC-recommended). */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /** The code for a given moment (defaults to now). */
    public function at(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);

        return $this->hotp($secret, $counter);
    }

    /**
     * Verify a user-supplied code against the current time ± $window steps.
     * Constant-time compare; whitespace/formatting in $code is tolerated.
     */
    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== $this->digits) {
            return false;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /** otpauth:// URI for QR-code enrolment. */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits'    => $this->digits,
            'period'    => $this->period,
        ]);

        return "otpauth://totp/{$label}?{$params}";
    }

    public function formatSecretForDisplay(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    // ---- internals -------------------------------------------------

    private function hotp(string $base32Secret, int $counter): string
    {
        $key = $this->base32Decode($base32Secret);
        $binCounter = pack('N*', 0, $counter); // 64-bit big-endian
        $hash = hash_hmac($this->algorithm, $binCounter, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary =
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** $this->digits);

        return str_pad((string) $otp, $this->digits, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
