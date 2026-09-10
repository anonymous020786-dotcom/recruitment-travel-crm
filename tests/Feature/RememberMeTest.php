<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\RememberMe;
use App\Http\Request;
use App\Models\User;
use App\Repositories\AuthTokenRepository;
use App\Repositories\UserRepository;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class RememberMeTest extends DbTestCase
{
    private RememberMe $remember;
    private AuthTokenRepository $tokens;
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
            'public_id' => Ulid::generate(), 'name' => 'RM User',
            'email' => 'rm_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $roleId, 'is_active' => 1,
        ]);
        $this->user = $this->app->get(UserRepository::class)->findById($this->userId);
        $this->tokens = new AuthTokenRepository($this->db);
        $this->remember = $this->app->get(RememberMe::class);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM auth_tokens WHERE user_id = ?', [$this->userId]);
        $this->db->affectingStatement('DELETE FROM users WHERE id = ?', [$this->userId]);
    }

    private function request(array $cookies = []): Request
    {
        return new Request([], [], $cookies, [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '127.0.0.1',
        ], '');
    }

    public function test_issue_creates_token_and_valid_cookie(): void
    {
        $spec = $this->remember->issue($this->user, $this->request());
        self::assertSame('crm_remember', $spec['name']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}:[a-f0-9]{64}$/', $spec['value']);
        self::assertTrue($spec['options']['httponly']);
        self::assertCount(1, $this->db->select('SELECT id FROM auth_tokens WHERE user_id = ?', [$this->userId]));
    }

    public function test_recall_returns_user_and_rotates_validator(): void
    {
        $issued = $this->remember->issue($this->user, $this->request())['value'];
        $hashBefore = (string) $this->db->selectValue('SELECT validator_hash FROM auth_tokens WHERE user_id = ?', [$this->userId]);

        [$user, $cookie] = $this->remember->recall($this->request(['crm_remember' => $issued]));

        self::assertInstanceOf(User::class, $user);
        self::assertSame($this->userId, $user->id);
        self::assertNotSame($issued, $cookie['value'], 'cookie rotated');

        $hashAfter = (string) $this->db->selectValue('SELECT validator_hash FROM auth_tokens WHERE user_id = ?', [$this->userId]);
        self::assertNotSame($hashBefore, $hashAfter);
    }

    public function test_stolen_cookie_replay_revokes_the_whole_series(): void
    {
        $issued = $this->remember->issue($this->user, $this->request())['value'];
        [$selector] = explode(':', $issued);

        // Attacker replays the ORIGINAL cookie after the legit user already rotated it.
        $this->remember->recall($this->request(['crm_remember' => $issued]));           // legit use -> rotates
        [$user, $cookie] = $this->remember->recall($this->request(['crm_remember' => $issued])); // replay of old validator

        self::assertNull($user);
        self::assertSame('', $cookie['value'], 'forget cookie');
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ?', [$this->userId]));
    }

    public function test_unknown_selector_is_ignored(): void
    {
        [$user] = $this->remember->recall($this->request(['crm_remember' => str_repeat('a', 24) . ':' . str_repeat('b', 64)]));
        self::assertNull($user);
    }

    public function test_forget_removes_the_series(): void
    {
        $issued = $this->remember->issue($this->user, $this->request())['value'];
        $this->remember->forget($this->request(['crm_remember' => $issued]));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ?', [$this->userId]));
    }

    public function test_expired_token_is_not_accepted(): void
    {
        $issued = $this->remember->issue($this->user, $this->request())['value'];
        $this->db->affectingStatement('UPDATE auth_tokens SET expires_at = (UTC_TIMESTAMP() - INTERVAL 1 DAY) WHERE user_id = ?', [$this->userId]);
        [$user] = $this->remember->recall($this->request(['crm_remember' => $issued]));
        self::assertNull($user);
    }
}
