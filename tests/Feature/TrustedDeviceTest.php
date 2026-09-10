<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\TrustedDevice;
use App\Http\Request;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class TrustedDeviceTest extends DbTestCase
{
    private TrustedDevice $devices;
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
            'public_id' => Ulid::generate(), 'name' => 'TD User',
            'email' => 'td_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $roleId, 'is_active' => 1,
        ]);
        $this->user = $this->app->get(UserRepository::class)->findById($this->userId);
        $this->devices = $this->app->get(TrustedDevice::class);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM trusted_devices WHERE user_id = ?', [$this->userId]);
        $this->db->affectingStatement('DELETE FROM users WHERE id = ?', [$this->userId]);
    }

    private function request(array $cookies = [], string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'): Request
    {
        return new Request([], [], $cookies, [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => $ua,
        ], '');
    }

    public function test_trust_then_recognised(): void
    {
        $spec = $this->devices->trust($this->user, $this->request());
        self::assertSame('crm_device', $spec['name']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $spec['value']);

        self::assertTrue($this->devices->isTrusted($this->user, $this->request(['crm_device' => $spec['value']])));
        self::assertFalse($this->devices->isTrusted($this->user, $this->request(['crm_device' => str_repeat('0', 64)])));

        $label = (string) $this->db->selectValue('SELECT label FROM trusted_devices WHERE user_id = ?', [$this->userId]);
        self::assertStringContainsString('Chrome on Windows', $label);
    }

    public function test_expired_trust_not_recognised(): void
    {
        $spec = $this->devices->trust($this->user, $this->request());
        $this->db->affectingStatement('UPDATE trusted_devices SET trusted_until = (UTC_TIMESTAMP() - INTERVAL 1 DAY) WHERE user_id = ?', [$this->userId]);
        self::assertFalse($this->devices->isTrusted($this->user, $this->request(['crm_device' => $spec['value']])));
    }

    public function test_forget_and_revoke_all(): void
    {
        $s1 = $this->devices->trust($this->user, $this->request());
        $this->devices->trust($this->user, $this->request());
        self::assertSame(2, (int) $this->db->selectValue('SELECT COUNT(*) FROM trusted_devices WHERE user_id = ?', [$this->userId]));

        $this->devices->forget($this->user, $this->request(['crm_device' => $s1['value']]));
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM trusted_devices WHERE user_id = ?', [$this->userId]));

        $this->devices->revokeAll($this->userId);
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM trusted_devices WHERE user_id = ?', [$this->userId]));
    }
}
