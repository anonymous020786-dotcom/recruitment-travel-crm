<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\LoginAlerts;
use App\Http\Request;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LoginAlertsTest extends DbTestCase
{
    private LoginAlerts $alerts;
    private User $user;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $roleId = (int) $this->db->selectValue('SELECT id FROM roles LIMIT 1');
        if ($roleId === 0) {
            self::markTestSkipped('roles not seeded');
        }
        $this->userId = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => 'LA User',
            'email' => 'la_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $roleId, 'is_active' => 1,
        ]);
        $this->user = $this->app->get(UserRepository::class)->findById($this->userId);
        $this->alerts = $this->app->get(LoginAlerts::class);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM login_history WHERE user_id = ?', [$this->userId]);
        $this->db->affectingStatement('DELETE FROM email_log WHERE to_email = ?', [$this->user->email]);
        $this->db->affectingStatement('DELETE FROM users WHERE id = ?', [$this->userId]);
    }

    private function request(string $ua): Request
    {
        return new Request([], [], [], [], [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => $ua,
        ], '');
    }

    private function alertsSent(): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM email_log WHERE to_email = ?', [$this->user->email]);
    }

    public function test_first_login_records_history_but_does_not_alert(): void
    {
        $this->alerts->afterLogin($this->user, $this->request('Chrome/120 Windows'), 'password');

        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM login_history WHERE user_id = ?', [$this->userId]));
        self::assertSame(0, $this->alertsSent());
    }

    public function test_new_device_triggers_alert(): void
    {
        $this->alerts->afterLogin($this->user, $this->request('Chrome/120 Windows'), 'password'); // baseline
        $this->alerts->afterLogin($this->user, $this->request('Safari/17 iPhone'), 'password');    // new device

        self::assertSame(1, $this->alertsSent());
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM login_history WHERE user_id = ? AND alerted = 1", [$this->userId],
        ));
    }

    public function test_known_device_does_not_alert(): void
    {
        $ua = 'Chrome/120 Windows';
        $this->alerts->afterLogin($this->user, $this->request($ua), 'password');
        $this->alerts->afterLogin($this->user, $this->request($ua), 'password');

        self::assertSame(0, $this->alertsSent());
    }

    public function test_respects_the_user_opt_out(): void
    {
        $this->db->affectingStatement('UPDATE users SET notify_new_device = 0 WHERE id = ?', [$this->userId]);
        $this->alerts->afterLogin($this->user, $this->request('Chrome/120 Windows'), 'password');
        $this->alerts->afterLogin($this->user, $this->request('Firefox/119 Linux'), 'password');

        self::assertSame(0, $this->alertsSent());
    }
}
