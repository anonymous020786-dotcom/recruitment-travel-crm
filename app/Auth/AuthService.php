<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditService;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Mail\Mailer;
use App\Models\User;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Support\Application;
use App\Support\Db;
use App\Support\Hash;
use App\Support\Logger;

/**
 * Authentication business logic: credential verification with per-account
 * lockout, password-reset issuance (enumeration-safe) and completion (single-use,
 * invalidates all of the user's sessions).
 *
 * Velocity limiting by IP/email is handled by the `throttle:login` /
 * `throttle:password_reset` route middleware; this service owns account lockout.
 */
final class AuthService
{
    public function __construct(
        private readonly Application $app,
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly PasswordResetRepository $resets,
        private readonly Hash $hash,
        private readonly Mailer $mailer,
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly AuditService $audit,
    ) {
    }

    private const GENERIC_FAILURE = 'These credentials do not match our records.';

    public function attempt(string $email, string $password, Request $request): User
    {
        $email = strtolower(trim($email));
        $ipBinary = $request->ipBinary();

        $cfg = (array) $this->app->config()->get('security.login', []);
        $maxAttempts = (int) ($cfg['max_attempts_per_window'] ?? 5);
        $lockoutMinutes = (int) ($cfg['lockout_minutes'] ?? 15);

        $user = $this->users->findByEmail($email);

        // Always do hash work so "user not found" and "wrong password" take the same time.
        $storedHash = $user !== null ? ($this->users->passwordHashFor($user->id) ?? '') : '';
        $passwordOk = $this->hash->verify($password, $storedHash);

        if ($user !== null && $user->isLocked()) {
            $this->attempts->record($email, $ipBinary, false);
            throw new HttpException(429, 'This account is temporarily locked after too many failed attempts. Try again later.', [
                'Retry-After' => (string) (max(1, strtotime((string) $user->lockedUntil) - time())),
            ]);
        }

        if ($user === null || !$user->isActive || !$passwordOk) {
            $this->attempts->record($email, $ipBinary, false);

            if ($user !== null && !$passwordOk) {
                $failures = $this->users->incrementFailedLogins($user->id);
                if ($failures >= $maxAttempts) {
                    $this->users->lockUntil($user->id, new \DateTimeImmutable("+{$lockoutMinutes} minutes"));
                    $this->logger->warning('account locked after {n} failed logins', ['n' => $failures, 'user_id' => $user->id]);
                }
            }

            throw new ValidationException(['email' => [self::GENERIC_FAILURE]]);
        }

        // Success.
        if ($this->hash->needsRehash($storedHash)
            && (bool) $this->app->config()->get('security.hash.rehash_on_login', true)) {
            $this->users->updatePasswordHash($user->id, $this->hash->make($password), clearMustChange: false);
        }

        $this->users->recordSuccessfulLogin($user->id, $ipBinary);
        $this->attempts->record($email, $ipBinary, true);
        $this->audit->log('login', 'auth', 'user', $user->id, null, null, 'password sign-in', $user);

        return $user;
    }

    /**
     * Issue a reset link. The response is identical whether or not the email
     * exists (no account enumeration).
     */
    public function sendResetLink(string $email, Request $request): void
    {
        $email = strtolower(trim($email));
        $user = $this->users->findByEmail($email);

        if ($user === null || !$user->isActive) {
            return;
        }

        $throttleMinutes = (int) $this->app->config()->get('auth.passwords.throttle_minutes', 2);
        $last = $this->resets->lastCreatedAtForUser($user->id);
        if ($last !== null && (time() - $last) < $throttleMinutes * 60) {
            return; // silently within throttle window
        }

        $token = bin2hex(random_bytes(32));
        $expireMinutes = (int) $this->app->config()->get('auth.passwords.expire_minutes', 60);
        $expiresAt = new \DateTimeImmutable("+{$expireMinutes} minutes");

        $this->resets->create($user->id, hash('sha256', $token), $expiresAt, $request->ipBinary());

        $url = rtrim((string) $this->app->config()->get('app.url', ''), '/')
            . '/reset-password/' . $token . '?email=' . rawurlencode($email);

        $appName = (string) $this->app->config()->get('app.name', 'CRM');
        $html = "<p>Hello {$this->e($user->name)},</p>"
            . "<p>We received a request to reset your {$this->e($appName)} password. "
            . "This link expires in {$expireMinutes} minutes and can be used once.</p>"
            . "<p><a href=\"{$this->e($url)}\">Reset your password</a></p>"
            . "<p>If you did not request this, no action is needed.</p>";

        $this->mailer->send($email, "Reset your {$appName} password", $html, null, 'password_reset');
        $this->logger->info('password reset link issued', ['user_id' => $user->id]);
    }

    public function resetPassword(string $token, string $email, string $newPassword): void
    {
        $email = strtolower(trim($email));
        $invalid = new ValidationException(['email' => ['This password reset link is invalid or has expired.']]);

        $record = $this->resets->findValid(hash('sha256', $token));
        if ($record === null) {
            throw $invalid;
        }

        $user = $this->users->findById($record['user_id']);
        if ($user === null || !$user->isActive || strcasecmp($user->email, $email) !== 0) {
            throw $invalid;
        }

        $this->db->transaction(function () use ($user, $newPassword, $record): void {
            $this->users->updatePasswordHash($user->id, $this->hash->make($newPassword));
            $this->resets->markUsed($record['id']);
            $this->resets->deleteForUser($user->id);
            $this->users->resetFailedLogins($user->id);
            // Cut every existing credential for this user: sessions, remember-me
            // tokens and trusted devices.
            $sessionTable = (string) $this->app->config()->get('session.table', 'sessions');
            $this->db->affectingStatement("DELETE FROM `{$sessionTable}` WHERE user_id = :uid", ['uid' => $user->id]);
            $this->db->affectingStatement('DELETE FROM auth_tokens WHERE user_id = :uid', ['uid' => $user->id]);
            $this->db->affectingStatement('DELETE FROM trusted_devices WHERE user_id = :uid', ['uid' => $user->id]);
        });

        $this->audit->log('password_reset', 'auth', 'user', $user->id, null, null, 'via reset link', $user);
        $this->logger->info('password reset completed', ['user_id' => $user->id]);
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
