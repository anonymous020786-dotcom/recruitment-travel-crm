<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\TwoFactor;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Support\Hash;
use App\Support\Totp;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class TwoFactorTest extends DbTestCase
{
    private TwoFactor $tf;
    private TwoFactorRepository $repo;
    private Totp $totp;
    private User $user;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $roleId = (int) ($this->db->selectValue("SELECT id FROM roles WHERE name='admin'") ?: $this->db->selectValue('SELECT id FROM roles LIMIT 1'));
        if ($roleId === 0) {
            self::markTestSkipped('roles not seeded');
        }
        $this->userId = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => '2FA User',
            'email' => 'tf_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $roleId, 'is_active' => 1,
        ]);
        $this->user = $this->app->get(UserRepository::class)->findById($this->userId);
        $this->tf = $this->app->get(TwoFactor::class);
        $this->repo = new TwoFactorRepository($this->db);
        $this->totp = $this->app->get(Totp::class);
    }

    protected function tearDown(): void
    {
        foreach (['auth_recovery_codes', 'auth_otp_codes'] as $t) {
            $this->db->affectingStatement("DELETE FROM {$t} WHERE user_id = ?", [$this->userId]);
        }
        $this->db->affectingStatement('DELETE FROM activity_logs WHERE record_type = ? AND record_id = ?', ['user', (string) $this->userId]);
        $this->db->affectingStatement('DELETE FROM users WHERE id = ?', [$this->userId]);
    }

    private function reload(): User
    {
        return $this->app->get(UserRepository::class)->findById($this->userId);
    }

    public function test_totp_enrolment_flow(): void
    {
        $enrol = $this->tf->beginTotpEnrolment($this->user);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $enrol['secret']);
        self::assertStringStartsWith('otpauth://totp/', $enrol['uri']);
        self::assertFalse($this->reload()->twoFactorEnabled, 'not enabled until confirmed');

        // Wrong code -> rejected.
        try {
            $this->tf->confirmTotpEnrolment($this->user, '000000');
            self::fail('expected rejection');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('code', $e->errors());
        }

        // Correct code -> enabled + 10 recovery codes.
        $recovery = $this->tf->confirmTotpEnrolment($this->user, $this->totp->at($enrol['secret']));
        self::assertCount(10, $recovery);
        self::assertTrue($this->reload()->twoFactorEnabled);
        self::assertSame(10, $this->tf->remainingRecoveryCodes($this->reload()));

        // The stored secret round-trips (verifyTotp works).
        self::assertTrue($this->tf->verifyTotp($this->reload(), $this->totp->at($enrol['secret'])));
        self::assertFalse($this->tf->verifyTotp($this->reload(), '123456'));

        // Recovery codes are single use.
        self::assertTrue($this->tf->verifyRecoveryCode($this->reload(), $recovery[0]));
        self::assertFalse($this->tf->verifyRecoveryCode($this->reload(), $recovery[0]));
        self::assertSame(9, $this->tf->remainingRecoveryCodes($this->reload()));
        self::assertTrue($this->tf->verifyRecoveryCode($this->reload(), strtolower(substr($recovery[1], 0, 5) . '-' . substr($recovery[1], 5)))); // tolerant

        // Disable wipes everything.
        $this->tf->disable($this->reload());
        self::assertFalse($this->reload()->twoFactorEnabled);
        self::assertNull($this->repo->getTotpSecret($this->userId));
        self::assertSame(0, $this->tf->remainingRecoveryCodes($this->reload()));

        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE action='2fa_enabled' AND record_id = ?", [$this->userId],
        ));
    }

    public function test_email_code_flow(): void
    {
        $request = new \App\Http\Request([], [], [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x', 'REMOTE_ADDR' => '127.0.0.1'], '');

        $this->tf->sendEmailCode($this->user, 'login_2fa', $request);
        self::assertTrue($this->db->exists("SELECT 1 FROM email_log WHERE to_email = ? AND template = 'otp'", [$this->user->email]));

        $hash = (string) $this->db->selectValue('SELECT code_hash FROM auth_otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$this->userId]);

        // Brute a few wrong codes, then the right one.
        self::assertFalse($this->tf->verifyEmailCode($this->user, 'login_2fa', '000000'));
        self::assertFalse($this->tf->verifyEmailCode($this->user, 'login_2fa', '111111'));

        // Recover the plaintext by finding which 6-digit code hashes to $hash is impractical;
        // instead assert consumption semantics with a directly-seeded code.
        $this->db->affectingStatement('DELETE FROM auth_otp_codes WHERE user_id = ?', [$this->userId]);
        $ref = new \ReflectionMethod($this->tf, 'hashCode');
        $ref->setAccessible(true);
        $this->repo->createOtp($this->userId, 'login_2fa', $ref->invoke($this->tf, '424242'), new \DateTimeImmutable('+10 minutes'), null);

        self::assertTrue($this->tf->verifyEmailCode($this->user, 'login_2fa', '424242'));
        self::assertFalse($this->tf->verifyEmailCode($this->user, 'login_2fa', '424242'), 'single use');

        $this->db->affectingStatement('DELETE FROM email_log WHERE to_email = ?', [$this->user->email]);
    }

    public function test_recovery_codes_are_never_hashed_with_a_missing_or_short_app_key(): void
    {
        $key = $this->app->config()->get('app.key');

        try {
            foreach (['', 'short'] as $bad) {
                $this->app->config()->set('app.key', $bad);
                try {
                    $this->tf->regenerateRecoveryCodes($this->user);
                    self::fail('hashed recovery codes with an unusable APP_KEY');
                } catch (\RuntimeException $e) {
                    self::assertStringContainsString('APP_KEY', $e->getMessage());
                }
            }
        } finally {
            $this->app->config()->set('app.key', $key);
        }
    }
}
