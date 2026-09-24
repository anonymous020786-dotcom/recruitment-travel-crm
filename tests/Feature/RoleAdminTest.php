<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionMatrix;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\RoleAdminService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Roles: the permission-matrix editor, and the rules that stop it being used to escalate privilege or lock people out. */
final class RoleAdminTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private string $token = '';
    private int $branch;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<array{role_id:int|string,permission_id:int|string}> the real matrix, put back afterwards */
    private array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->snapshot = $this->db->select('SELECT role_id, permission_id FROM role_permissions');
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'RA branch', 'code' => 'RAX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM role_permissions');
        if ($this->snapshot !== []) {
            $bind = [];
            foreach ($this->snapshot as $row) {
                array_push($bind, $row['role_id'], $row['permission_id']);
            }
            $this->db->affectingStatement('INSERT INTO role_permissions (role_id, permission_id) VALUES ' . implode(',', array_fill(0, count($this->snapshot), '(?, ?)')), $bind);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'roles' AND action LIKE 'role\\_permissions\\_%'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['RAX-%']);
    }

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "RA {$role}", 'email' => 'ra_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function model(int $id): User
    {
        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function service(): RoleAdminService
    {
        return $this->app->get(RoleAdminService::class);
    }

    /** @return list<string> */
    private function granted(string $role): array
    {
        $names = array_map(static fn (array $r): string => (string) $r['name'], $this->db->select(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
            [$this->roles[$role]],
        ));
        sort($names);

        return $names;
    }

    private function refused(callable $do, string $code): void
    {
        try {
            $do();
            self::fail("expected the rule {$code}");
        } catch (DomainRuleException $e) {
            self::assertSame($code, $e->ruleCode(), $e->getMessage());
        }
    }

    // ---- the matrix resolver -------------------------------------------------------------------------

    public function test_matrix_tokens_expand_grant_then_revoke(): void
    {
        $all = ['a.view', 'a.edit', 'b.view', 'b.edit'];
        $byModule = ['a' => ['a.view', 'a.edit'], 'b' => ['b.view', 'b.edit']];

        self::assertSame($all, PermissionMatrix::resolve(['*'], $all, $byModule));
        self::assertSame(['a.view', 'a.edit', 'b.view'], PermissionMatrix::resolve(['a.*', 'b.view'], $all, $byModule));
        self::assertSame(['a.view', 'b.view', 'b.edit'], array_values(PermissionMatrix::resolve(['!a.edit', '*'], $all, $byModule)), 'a revoke wins wherever it sits');
        self::assertSame([], PermissionMatrix::resolve(['nope.*'], $all, $byModule));
    }

    // ---- editing ----------------------------------------------------------------------------------------

    public function test_a_change_is_saved_audited_and_takes_effect_for_the_role_immediately(): void
    {
        $super = $this->model($this->user('super_admin'));
        $person = $this->model($this->user('counselor'));
        $before = $this->granted('counselor');
        self::assertNotContains('refunds.view', $before);
        self::assertContains('tasks.complete', $before);

        $names = array_values(array_diff([...$before, 'refunds.view'], ['tasks.complete']));
        $diff = $this->service()->update('counselor', $names, $super);

        self::assertSame(['added' => ['refunds.view'], 'removed' => ['tasks.complete']], $diff);
        $after = $this->granted('counselor');
        self::assertContains('refunds.view', $after);
        self::assertNotContains('tasks.complete', $after);

        $fresh = new PermissionService($this->app->get(\App\Repositories\PermissionRepository::class));
        self::assertTrue($fresh->userCan($person, 'refunds.view'));
        self::assertFalse($fresh->userCan($person, 'tasks.complete'));

        $log = (string) $this->db->selectValue("SELECT new_values FROM activity_logs WHERE action = 'role_permissions_changed' AND record_id = ?", [$this->roles['counselor']]);
        self::assertStringContainsString('refunds.view', $log);
        self::assertStringContainsString('tasks.complete', $log);

        // Saving the same set again changes nothing and writes no audit row.
        $rows = (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'role_permissions_changed'");
        self::assertSame(['added' => [], 'removed' => []], $this->service()->update('counselor', $names, $super));
        self::assertSame($rows, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'role_permissions_changed'"));
    }

    public function test_reset_restores_the_shipped_default(): void
    {
        $super = $this->model($this->user('super_admin'));
        $default = $this->granted('counselor');
        $this->service()->update('counselor', ['dashboard.view'], $super);
        self::assertSame(['dashboard.view'], $this->granted('counselor'));

        $diff = $this->service()->reset('counselor', $super);

        self::assertSame($default, $this->granted('counselor'));
        self::assertNotEmpty($diff['added']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'role_permissions_reset' AND record_id = ?", [$this->roles['counselor']]));
    }

    public function test_every_shipped_default_satisfies_the_rules_it_is_edited_under(): void
    {
        $super = $this->model($this->user('super_admin'));
        foreach (array_keys($this->roles) as $role) {
            if ($role === 'super_admin') {
                continue;
            }
            $defaults = $this->service()->defaultsFor($role);
            $this->service()->update($role, $defaults, $super);   // would throw if the shipped matrix broke its own rules
            self::assertSame($defaults, $this->granted($role), $role);
        }
    }

    // ---- the rules ---------------------------------------------------------------------------------------------

    public function test_only_a_super_admin_may_edit_and_the_super_admin_role_is_fixed(): void
    {
        $admin = $this->model($this->user('admin'));
        $super = $this->model($this->user('super_admin'));

        try {
            $this->service()->update('counselor', ['dashboard.view'], $admin);
            self::fail('an admin edited the matrix');
        } catch (AuthorizationException) {
            self::assertNotSame(['dashboard.view'], $this->granted('counselor'));
        }
        $this->refused(fn () => $this->service()->update('super_admin', ['dashboard.view'], $super), 'rule_violation');
        $this->refused(fn () => $this->service()->update('no_such_role', ['dashboard.view'], $super), 'rule_violation');
    }

    public function test_roles_manage_can_never_be_granted_to_another_role(): void
    {
        $super = $this->model($this->user('super_admin'));
        $before = $this->granted('manager');

        $this->refused(fn () => $this->service()->update('manager', [...$before, 'roles.manage'], $super), 'ROLE_SUPER_ONLY');
        self::assertSame($before, $this->granted('manager'));
        self::assertNotContains('roles.manage', $this->service()->defaultsFor('admin'));
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $super = $this->model($this->user('super_admin'));
        $before = $this->granted('manager');

        try {
            $this->service()->update('manager', [...$before, 'leads.teleport'], $super);
            self::fail('accepted an unknown permission');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('permissions', $e->errors());
        }
        self::assertSame($before, $this->granted('manager'));
    }

    public function test_acting_in_a_module_requires_being_able_to_view_it(): void
    {
        $super = $this->model($this->user('super_admin'));
        $before = $this->granted('counselor');
        self::assertNotContains('refunds.view', $before);

        $this->refused(fn () => $this->service()->update('counselor', [...$before, 'refunds.create'], $super), 'ROLE_NEEDS_VIEW');
        self::assertSame($before, $this->granted('counselor'));
        $this->service()->update('counselor', [...$before, 'refunds.create', 'refunds.view'], $super);
        self::assertContains('refunds.create', $this->granted('counselor'));
    }

    public function test_the_admin_role_cannot_be_stripped_of_its_way_back_in(): void
    {
        $super = $this->model($this->user('super_admin'));
        $before = $this->granted('admin');

        foreach (['users.manage', 'users.view', 'dashboard.view'] as $essential) {
            $this->refused(fn () => $this->service()->update('admin', array_values(array_diff($before, [$essential])), $super), 'ROLE_ADMIN_FLOOR');
        }
        self::assertSame($before, $this->granted('admin'));
    }

    // ---- the screens ---------------------------------------------------------------------------------------------

    private function actAs(int $userId, bool $confirmed = true): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => $confirmed ? time() : time() - 7200, '_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    private function send(string $method, string $uri, array $post = []): Response
    {
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }

        return $this->router->dispatch(new Request([], $post, ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    private function code(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->send($method, $uri, $post)->getStatus();
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        } catch (AuthorizationException) {
            return 403;
        }
    }

    public function test_the_screens_are_for_the_super_admin_alone(): void
    {
        $this->actAs($this->user('admin'));
        self::assertSame(403, $this->code('GET', '/admin/roles'));
        self::assertSame(403, $this->code('GET', '/admin/roles/counselor'));
        self::assertSame(403, $this->code('PUT', '/admin/roles/counselor', ['_method' => 'PUT', 'permissions' => ['dashboard.view']]));
        self::assertSame(403, $this->code('POST', '/admin/roles/counselor/reset', ['confirm' => '1']));

        $this->actAs($this->user('super_admin'));
        self::assertSame(200, $this->code('GET', '/admin/roles'));
        self::assertSame(200, $this->code('GET', '/admin/roles/counselor'));
        self::assertSame(200, $this->code('GET', '/admin/roles/super_admin'));
        self::assertSame(404, $this->code('GET', '/admin/roles/no_such_role'));
    }

    public function test_saving_through_the_screen_updates_the_role_and_shows_what_changed(): void
    {
        $this->actAs($this->user('super_admin'));
        $wanted = ['dashboard.view', 'leads.edit', 'leads.view'];

        $res = $this->send('PUT', '/admin/roles/counselor', ['_method' => 'PUT', 'permissions' => $wanted]);

        self::assertSame(302, $res->getStatus());
        self::assertSame($wanted, $this->granted('counselor'));
        $page = $this->send('GET', '/admin/roles/counselor')->getBody();
        self::assertStringContainsString('name="permissions[]" value="leads.edit"', $page);
        self::assertStringContainsString('differs from default', $page);
    }

    public function test_saving_needs_a_fresh_password_confirmation(): void
    {
        $this->actAs($this->user('super_admin'), false);
        $before = $this->granted('counselor');

        $res = $this->send('PUT', '/admin/roles/counselor', ['_method' => 'PUT', 'permissions' => ['dashboard.view']]);

        self::assertSame(302, $res->getStatus());
        self::assertSame('/confirm-password', $res->getHeader('Location'));
        self::assertSame($before, $this->granted('counselor'));
        self::assertSame('/confirm-password', $this->send('POST', '/admin/roles/counselor/reset', ['x' => '1'])->getHeader('Location'));
    }

    public function test_a_refused_change_leaves_the_role_untouched_and_explains_why(): void
    {
        $this->actAs($this->user('super_admin'));
        $before = $this->granted('manager');
        $superBefore = $this->granted('super_admin');

        $res = $this->send('PUT', '/admin/roles/manager', ['_method' => 'PUT', 'permissions' => [...$before, 'roles.manage']]);

        self::assertSame(302, $res->getStatus());
        self::assertSame($before, $this->granted('manager'));
        $res = $this->send('PUT', '/admin/roles/super_admin', ['_method' => 'PUT', 'permissions' => ['dashboard.view']]);
        self::assertSame(302, $res->getStatus());
        self::assertSame($superBefore, $this->granted('super_admin'));
    }
}
