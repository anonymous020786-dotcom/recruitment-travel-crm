<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Http\Middleware\Enforce2fa;
use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use App\Support\Config;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class Enforce2faTest extends DbTestCase
{
    private int $userId;
    private string $roleName;

    protected function setUp(): void
    {
        parent::setUp();
        $role = $this->db->selectOne('SELECT id, name FROM roles ORDER BY id LIMIT 1');
        if ($role === null) {
            self::markTestSkipped('roles not seeded');
        }
        $this->roleName = (string) $role['name'];

        $this->userId = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(),
            'name' => 'E2FA User',
            'email' => 'e2fa_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => (int) $role['id'],
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM login_history WHERE user_id = ?', [$this->userId]);
        $this->db->affectingStatement('DELETE FROM users WHERE id = ?', [$this->userId]);
    }

    private function logins(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->db->affectingStatement(
                "INSERT INTO login_history (user_id, ua_hash, user_agent, via, created_at)
                 VALUES (?, ?, 'UA', 'password', UTC_TIMESTAMP())",
                [$this->userId, hash('sha256', 'ua' . $i)],
            );
        }
    }

    private function dispatch(string $path = '/leads', string $via = 'password', array $server = []): Response
    {
        $session = new Session('sess', ['_auth_user_id' => $this->userId, '_auth_via' => $via]);
        $this->app->instance(Session::class, $session);

        $request = new Request([], [], [], [], $server + [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path,
        ], '');
        $request->setAttribute('session', $session);

        $mw = new Enforce2fa($this->app, $this->app->get(Auth::class));

        return $mw->handle($request, fn ($r) => Response::text('ok'));
    }

    private function requireRole(): void
    {
        $this->app->get(Config::class)->set('auth.two_factor.required_roles', [$this->roleName]);
        $this->app->get(Config::class)->set('auth.two_factor.grace_logins', 3);
    }

    public function test_no_op_when_role_is_not_required(): void
    {
        $this->app->get(Config::class)->set('auth.two_factor.required_roles', []);
        self::assertSame('ok', $this->dispatch()->getBody());
    }

    public function test_within_grace_allows_and_sets_countdown(): void
    {
        $this->requireRole();
        $this->logins(1);

        $session = new Session('sess', ['_auth_user_id' => $this->userId, '_auth_via' => 'password']);
        $this->app->instance(Session::class, $session);
        $request = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/leads'], '');
        $request->setAttribute('session', $session);
        $res = (new Enforce2fa($this->app, $this->app->get(Auth::class)))->handle($request, fn ($r) => Response::text('ok'));

        self::assertSame('ok', $res->getBody());
        self::assertSame(2, $request->attribute('twofa_grace_left'));
    }

    public function test_after_grace_redirects_to_setup(): void
    {
        $this->requireRole();
        $this->logins(4);

        $res = $this->dispatch('/leads');
        self::assertSame(302, $res->getStatus());
        self::assertSame('/account/two-factor', $res->getHeader('Location'));
    }

    public function test_after_grace_still_allows_the_setup_page(): void
    {
        $this->requireRole();
        $this->logins(9);

        self::assertSame('ok', $this->dispatch('/account/two-factor')->getBody());
        self::assertSame('ok', $this->dispatch('/account/passkeys')->getBody());
        self::assertSame('ok', $this->dispatch('/logout')->getBody());
    }

    public function test_after_grace_json_gets_403(): void
    {
        $this->requireRole();
        $this->logins(5);

        $res = $this->dispatch('/leads', 'password', ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(403, $res->getStatus());
        self::assertTrue(json_decode($res->getBody(), true)['twofa_required']);
    }

    public function test_passkey_login_is_exempt(): void
    {
        $this->requireRole();
        $this->logins(9);

        self::assertSame('ok', $this->dispatch('/leads', 'passkey')->getBody());
    }
}
