<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditService;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Mail\Mailer;
use App\Models\User;
use App\Repositories\TwoFactorRepository;
use App\Support\Application;
use App\Support\Encryptor;
use App\Support\Logger;
use App\Support\Totp;

/**
 * Two-factor: TOTP (authenticator app) with single-use recovery codes, plus an
 * email one-time code used as a fallback second factor and for step-up on
 * sensitive actions.
 */
final class TwoFactor
{
    public function __construct(
        private readonly Application $app,
        private readonly TwoFactorRepository $repo,
        private readonly Totp $totp,
        private readonly Encryptor $encryptor,
        private readonly Mailer $mailer,
        private readonly AuditService $audit,
        private readonly Logger $logger,
    ) {
    }

    // ---- policy ---------------------------------------------------

    public function enabledFor(User $user): bool
    {
        return $user->twoFactorEnabled;
    }

    public function requiredFor(User $user): bool
    {
        $roles = (array) $this->app->config()->get('auth.two_factor.required_roles', []);

        return in_array($user->roleName, $roles, true);
    }

    // ---- TOTP enrolment ----------------------------------------

    /** @return array{secret:string,uri:string} plaintext secret shown once during setup */
    public function beginTotpEnrolment(User $user): array
    {
        $secret = $this->totp->generateSecret();
        $this->repo->storeTotpSecret($user->id, $this->encryptor->encrypt($secret));

        $issuer = (string) $this->app->config()->get('auth.two_factor.issuer', $this->app->config()->get('app.name', 'CRM'));

        return [
            'secret' => $secret,
            'uri'    => $this->totp->provisioningUri($secret, $user->email, $issuer),
        ];
    }

    /**
     * Confirm enrolment with the first code. Returns the plaintext recovery
     * codes (shown once).
     *
     * @return list<string>
     */
    public function confirmTotpEnrolment(User $user, string $code): array
    {
        $secret = $this->decryptedSecret($user->id);
        if ($secret === null || !$this->totp->verify($secret, $code)) {
            throw new ValidationException(['code' => ['That code is not correct. Check your authenticator app and try again.']]);
        }

        $this->repo->confirmTotp($user->id);
        $codes = $this->generateRecoveryCodes();
        $this->repo->replaceRecoveryCodes($user->id, array_map($this->hashCode(...), $codes));

        $this->audit->log('2fa_enabled', 'auth', 'user', $user->id, null, ['method' => 'totp'], null, $user);

        return $codes;
    }

    public function verifyTotp(User $user, string $code): bool
    {
        $secret = $this->decryptedSecret($user->id);

        return $secret !== null && $this->totp->verify($secret, $code);
    }

    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if ($normalised === '') {
            return false;
        }

        $ok = $this->repo->consumeRecoveryCode($user->id, $this->hashCode($normalised));
        if ($ok) {
            $this->audit->log('2fa_recovery_used', 'auth', 'user', $user->id, null,
                ['remaining' => $this->repo->remainingRecoveryCodes($user->id)], null, $user);
        }

        return $ok;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();
        $this->repo->replaceRecoveryCodes($user->id, array_map($this->hashCode(...), $codes));
        $this->audit->log('2fa_recovery_regenerated', 'auth', 'user', $user->id, null, null, null, $user);

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return $this->repo->remainingRecoveryCodes($user->id);
    }

    public function disable(User $user, ?User $actor = null): void
    {
        $this->repo->disable($user->id);
        $this->audit->log('2fa_disabled', 'auth', 'user', $user->id, ['enabled' => true], ['enabled' => false], null, $actor ?? $user);
    }

    // ---- Email one-time code ---------------------------------

    public function sendEmailCode(User $user, string $purpose, Request $request): void
    {
        $throttleSeconds = (int) $this->app->config()->get('auth.two_factor.email_resend_seconds', 60);
        $last = $this->repo->lastOtpCreatedAt($user->id, $purpose);
        if ($last !== null && (time() - $last) < $throttleSeconds) {
            return; // silently within the resend window
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int) $this->app->config()->get('auth.two_factor.email_ttl_minutes', 10);

        $this->repo->createOtp(
            $user->id,
            $purpose,
            $this->hashCode($code),
            new \DateTimeImmutable("+{$ttl} minutes"),
            $request->ipBinary(),
        );

        $appName = (string) $this->app->config()->get('app.name', 'CRM');
        $html = "<p>Hello {$this->e($user->name)},</p>"
            . "<p>Your {$this->e($appName)} verification code is:</p>"
            . "<p style=\"font-size:22px;letter-spacing:3px;font-weight:bold\">{$code}</p>"
            . "<p>It expires in {$ttl} minutes. If you did not request it, change your password.</p>";

        $this->mailer->send($user->email, "{$appName} verification code: {$code}", $html, "Your verification code is {$code}", 'auth_otp');
        $this->logger->info('2fa email code issued', ['user_id' => $user->id, 'purpose' => $purpose]);
    }

    public function verifyEmailCode(User $user, string $purpose, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $record = $this->repo->latestValidOtp($user->id, $purpose);
        if ($record === null) {
            return false;
        }

        if ($record['attempts'] >= 5) {
            $this->repo->consumeOtp($record['id']); // burn it
            throw new HttpException(429, 'Too many attempts. Request a new code.');
        }

        if (!hash_equals($record['code_hash'], $this->hashCode($code))) {
            $this->repo->incrementOtpAttempts($record['id']);

            return false;
        }

        $this->repo->consumeOtp($record['id']);

        return true;
    }

    // ---- internals -------------------------------------------

    private function decryptedSecret(int $userId): ?string
    {
        $encrypted = $this->repo->getTotpSecret($userId);

        return $encrypted === null ? null : $this->encryptor->decrypt($encrypted);
    }

    /** @return list<string> */
    private function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10)); // 10 hex chars
        }

        return $codes;
    }

    private function hashCode(string $value): string
    {
        return hash_hmac('sha256', $value, (string) $this->app->config()->get('app.key', 'k'));
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
