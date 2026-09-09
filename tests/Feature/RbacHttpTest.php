<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;
use App\Session\ArraySessionStore;
use App\Session\Session;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/**
 * A `can:`-gated route returns 403 for a user without the permission and 200
 * for one with it — verified through the real middleware stack against the
 * seeded permission tables.
 */
final class RbacHttpTest extends DbTestCase
{
    private Router $router;
    /** @var array<string,int> role name => id */
    private array $roles = [];
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->exists('SELECT 1 FROM role_permissions LIMIT 1')) {
            self::markTestSkipped('permissions not seeded — run php scripts/seed.php');
        }

        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }

        $this->app->instance(SessionStore::class, new ArraySessionStore());
        $this->app->instance(Session::class, new Session(str_repeat('a', 64)));
        $this->app->singleton(PermissionService::class, fn () => new PermissionService(new PermissionRepository($this->db)));
        $this->app->singleton(BranchScopeResolver::class, fn () => new BranchScopeResolver($this->app, $this->db));
        $this->app->singleton(Auth::class, fn () => new Auth($this->app, new UserRepository($this->db)));
        $this->app->singleton(Gate::class, fn () => new Gate($this->app, $this->app->get(PermissionService::class), $this->app->get(Auth::class)));

        $this->router = new Router($this->app);
        $this->router->get('/secure/leads', fn () => Response::json(['ok' => true]))
            ->middleware(['auth', 'can:leads.view']);
        $this->router->get('/secure/finance', fn () => Response::json(['ok' => true]))
            ->middleware(['auth', 'can:reports.finance.view']);
        $this->router->finalizeNames();
    }

    protected function tearDown(): void
    {
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
    }

    private function makeUser(string $role): int
    {
        $hash = new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]);
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(),
            'name' => "RBAC {$role}",
            'email' => 'rbac_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => $hash->make('x'),
            'role_id' => $this->roles[$role],
            'is_active' => 1,
        ]);
        $this->userIds[] = $id;

        return $id;
    }

    private function actAs(int $userId): void
    {
        /** @var Session $session */
        $session = $this->app->get(Session::class);
        $session->put('_auth_user_id', $userId);
        // Fresh Auth so it re-resolves the user.
        $this->app->instance(Auth::class, new Auth($this->app, new UserRepository($this->db)));
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $this->app->get(Auth::class)));
    }

    private function request(string $uri): Request
    {
        $req = new Request([], [], [], [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1',
        ], '');
        $req->setAttribute('session', $this->app->get(Session::class));

        return $req;
    }

    private function hitStatus(string $uri): int
    {
        try {
            return $this->router->dispatch($this->request($uri))->getStatus();
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        } catch (\App\Exceptions\AuthorizationException) {
            return 403;
        }
    }

    public function test_counselor_can_view_leads_but_not_finance_reports(): void
    {
        $this->actAs($this->makeUser('counselor'));
        self::assertSame(200, $this->hitStatus('/secure/leads'));
        self::assertSame(403, $this->hitStatus('/secure/finance'));
    }

    public function test_accounts_role_can_view_finance_reports(): void
    {
        $this->actAs($this->makeUser('accounts'));
        self::assertSame(200, $this->hitStatus('/secure/finance'));
    }

    public function test_read_only_cannot_reach_finance(): void
    {
        $this->actAs($this->makeUser('read_only'));
        self::assertSame(403, $this->hitStatus('/secure/finance'));
        self::assertSame(200, $this->hitStatus('/secure/leads'));
    }

    public function test_super_admin_passes_everything(): void
    {
        $this->actAs($this->makeUser('super_admin'));
        self::assertSame(200, $this->hitStatus('/secure/leads'));
        self::assertSame(200, $this->hitStatus('/secure/finance'));
    }

    public function test_deny_override_blocks_a_role_grant(): void
    {
        $id = $this->makeUser('accounts');
        $permId = (int) $this->db->selectValue("SELECT id FROM permissions WHERE name = 'reports.finance.view'");
        $this->db->affectingStatement(
            "INSERT INTO user_permissions (user_id, permission_id, effect) VALUES (?, ?, 'deny')",
            [$id, $permId],
        );
        $this->actAs($id);

        self::assertSame(403, $this->hitStatus('/secure/finance'));

        $this->db->affectingStatement('DELETE FROM user_permissions WHERE user_id = ?', [$id]);
    }
}
