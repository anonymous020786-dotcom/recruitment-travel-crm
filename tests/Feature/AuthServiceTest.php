<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AuthService;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Mail\QueueMailer;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class AuthServiceTest extends DbTestCase
{
    private AuthService $service;
    private UserRepository $users;
    private Hash $hash;
    private string $email;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hash = new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]);
        $this->users = new UserRepository($this->db);

        $this->service = new AuthService(
            $this->app,
            $this->users,
            new LoginAttemptRepository($this->db),
            new PasswordResetRepository($this->db),
            $this->hash,
            new QueueMailer($this->db, $this->app->get(\App\Support\Logger::class)),
            $this->db,
            $this->app->get(\App\Support\Logger::class),
            new \App\Audit\AuditService(
                $this->app,
                new \App\Repositories\ActivityLogRepository($this->db),
                $this->app->get(\App\Support\Logger::class),
            ),
        );

        $this->email = 'authtest_' . bin2hex(random_bytes(5)) . '@dev.local';
        $roleId = (int) $this->db->selectValue("SELECT id FROM roles WHERE name = 'admin'")
            ?: (int) $this->db->selectValue('SELECT id FROM roles LIMIT 1');
        self::assertGreaterThan(0, $roleId, 'roles must be seeded');

        $this->userId = (int) $this->db->insertRow('users', [
            'public_id'     => Ulid::generate(),
            'name'          => 'Auth Test',
            'email'         => $this->email,
            'password_hash' => $this->hash->make('CorrectHorse10'),
            'role_id'       => $roleId,
            'is_active'     => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM password_resets WHERE user_id = ?', [$this->userId]);
        $this->db->affectingStatement('DELETE FROM email_log WHERE to_email = ?', [$this->email]);
        $this->db->affectingStatement('DELETE FROM activity_logs WHERE record_type = ? AND record_id = ?', ['user', (string) $this->userId]);
        $this->cleanupUsers('authtest_%@dev.local');
    }

    private function request(): Request
    {
        return new Request([], [], [], [], [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'REMOTE_ADDR' => '127.0.0.1',
        ], '');
    }

    public function test_successful_login_returns_user_and_clears_failures(): void
    {
        $this->db->affectingStatement('UPDATE users SET failed_login_count = 2 WHERE id = ?', [$this->userId]);

        $user = $this->service->attempt($this->email, 'CorrectHorse10', $this->request());

        self::assertSame($this->userId, $user->id);
        self::assertSame(0, $this->users->failedLoginCount($this->userId));
        self::assertTrue($this->db->exists('SELECT 1 FROM login_attempts WHERE email = ? AND successful = 1', [$this->email]));
    }

    public function test_wrong_password_throws_generic_and_increments_failures(): void
    {
        try {
            $this->service->attempt($this->email, 'wrong', $this->request());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('do not match', $e->first() ?? '');
        }
        self::assertSame(1, $this->users->failedLoginCount($this->userId));
    }

    public function test_unknown_email_is_indistinguishable(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->attempt('nobody_' . bin2hex(random_bytes(4)) . '@dev.local', 'whatever', $this->request());
    }

    public function test_account_locks_after_five_failures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->service->attempt($this->email, 'wrong', $this->request());
            } catch (ValidationException) {
            }
        }

        // 6th attempt — even with the CORRECT password — is refused while locked.
        try {
            $this->service->attempt($this->email, 'CorrectHorse10', $this->request());
            self::fail('expected lockout');
        } catch (HttpException $e) {
            self::assertSame(429, $e->getStatusCode());
        }
    }

    public function test_send_reset_link_queues_email_for_known_user(): void
    {
        $this->service->sendResetLink($this->email, $this->request());

        self::assertTrue($this->db->exists('SELECT 1 FROM password_resets WHERE user_id = ?', [$this->userId]));
        self::assertTrue($this->db->exists("SELECT 1 FROM email_log WHERE to_email = ? AND status = 'queued'", [$this->email]));

        $hash = (string) $this->db->selectValue('SELECT token_hash FROM password_resets WHERE user_id = ?', [$this->userId]);
        self::assertSame(64, strlen($hash), 'token is stored hashed');
    }

    public function test_send_reset_link_is_silent_for_unknown_user(): void
    {
        $before = (int) $this->db->selectValue('SELECT COUNT(*) FROM password_resets');
        $this->service->sendResetLink('ghost_' . bin2hex(random_bytes(4)) . '@dev.local', $this->request());
        self::assertSame($before, (int) $this->db->selectValue('SELECT COUNT(*) FROM password_resets'));
    }

    public function test_reset_password_changes_hash_and_is_single_use(): void
    {
        $token = bin2hex(random_bytes(32));
        (new PasswordResetRepository($this->db))->create(
            $this->userId,
            hash('sha256', $token),
            new \DateTimeImmutable('+1 hour'),
            inet_pton('127.0.0.1'),
        );

        $this->service->resetPassword($token, $this->email, 'BrandNewPass99');

        // New password works.
        $user = $this->service->attempt($this->email, 'BrandNewPass99', $this->request());
        self::assertSame($this->userId, $user->id);

        // Token cannot be reused.
        $this->expectException(ValidationException::class);
        $this->service->resetPassword($token, $this->email, 'AnotherPass99');
    }

    public function test_reset_password_rejects_wrong_email_for_token(): void
    {
        $token = bin2hex(random_bytes(32));
        (new PasswordResetRepository($this->db))->create(
            $this->userId,
            hash('sha256', $token),
            new \DateTimeImmutable('+1 hour'),
            inet_pton('127.0.0.1'),
        );

        $this->expectException(ValidationException::class);
        $this->service->resetPassword($token, 'someone-else@dev.local', 'Whatever123');
    }
}
