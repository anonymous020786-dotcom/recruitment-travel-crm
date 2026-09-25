<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\RoleAdminRepository;
use App\Repositories\UserRepository;
use App\Services\UserPermissionService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Users → Permissions: allow/deny single permissions on top of a role, with expiry, guard rails, audit and access. */
final class UserPermissionOverridesTest extends DbTestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'UP branch', 'code' => 'UPX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_permissions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'users' AND action LIKE 'permission\\_override%'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['UPX-%']);
    }

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "UP {$role}", 'email' => 'up_' . bin2hex(random_bytes(4)) . '@dev.local',
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

    private function publicId(int $id): string
    {
        return (string) $this->db->selectValue('SELECT public_id FROM users WHERE id = :i', ['i' => $id]);
    }

    private function svc(): UserPermissionService
    {
        return $this->app->get(UserPermissionService::class);
    }

    private function can(int $userId, string $permission): bool
    {
        $resolver = $this->app->get(PermissionService::class);
        $resolver->forget($userId);

        return $resolver->userCan($this->model($userId), $permission);
    }

    /** @return array{0:string,1:string} [a permission the role holds, one it lacks] */
    private function heldAndMissing(string $role): array
    {
        $repo = $this->app->get(RoleAdminRepository::class);
        $held = $repo->grantedNames($this->roles[$role]);
        $all = array_merge(...array_map(static fn (array $items): array => array_column($items, 'name'), array_values($repo->catalogue())));
        $missing = array_values(array_diff($all, $held, ['roles.manage']));
        self::assertNotSame([], $held);
        self::assertNotSame([], $missing);

        return [$held[0], $missing[0]];
    }

    private function actAs(int $userId, bool $confirmed = true): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => $confirmed ? time() : time() - 7200, '_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    /** @param array<string,mixed> $post */
    private function send(string $method, string $uri, array $post = []): Response
    {
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }

        return $this->router->dispatch(new Request([], $post, ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    /** @param array<string,mixed> $post */
    private function code(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->send($method, $uri, $post)->getStatus();
        } catch (AuthorizationException) {
            return 403;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ---- the effect ------------------------------------------------------------------------------------------------

    public function test_an_allow_adds_to_the_role_and_a_deny_takes_away_and_removing_restores(): void
    {
        [$held, $missing] = $this->heldAndMissing('read_only');
        $target = $this->user('read_only');
        $super = $this->model($this->user('super_admin'));

        self::assertTrue($this->can($target, $held));
        self::assertFalse($this->can($target, $missing));

        $this->svc()->set($this->publicId($target), $missing, 'allow', null, 'covering', $super);
        $this->svc()->set($this->publicId($target), $held, 'deny', null, null, $super);
        self::assertTrue($this->can($target, $missing), 'the allow adds a permission the role lacks');
        self::assertFalse($this->can($target, $held), 'the deny beats the role');

        self::assertTrue($this->svc()->remove($this->publicId($target), $missing, $super));
        self::assertFalse($this->can($target, $missing));
        self::assertFalse($this->svc()->remove($this->publicId($target), $missing, $super), 'removing twice is harmless');

        self::assertSame(1, $this->svc()->clear($this->publicId($target), $super));
        self::assertTrue($this->can($target, $held), 'back to exactly what the role gives');
        self::assertSame(0, $this->svc()->clear($this->publicId($target), $super));
    }

    public function test_setting_the_same_permission_again_replaces_the_override(): void
    {
        [$held] = $this->heldAndMissing('accounts');
        $target = $this->user('accounts');
        $super = $this->model($this->user('super_admin'));

        $this->svc()->set($this->publicId($target), $held, 'deny', null, 'first', $super);
        $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
        $this->svc()->set($this->publicId($target), $held, 'deny', $tomorrow, 'second', $super);
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM user_permissions WHERE user_id = :u', ['u' => $target]));
        $row = $this->db->selectOne('SELECT expires_at, note, granted_by FROM user_permissions WHERE user_id = :u', ['u' => $target]);
        self::assertSame($tomorrow . ' 23:59:59', $row['expires_at']);
        self::assertSame('second', $row['note']);
        self::assertSame($super->id, (int) $row['granted_by']);
    }

    public function test_an_override_stops_applying_the_moment_it_expires_and_is_cleaned_up_later(): void
    {
        [, $missing] = $this->heldAndMissing('read_only');
        $target = $this->user('read_only');
        $super = $this->model($this->user('super_admin'));

        $this->svc()->set($this->publicId($target), $missing, 'allow', gmdate('Y-m-d'), null, $super);   // today = until 23:59:59 UTC
        self::assertTrue($this->can($target, $missing), 'still valid today');

        $this->db->affectingStatement('UPDATE user_permissions SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = :u', ['u' => $target]);
        self::assertFalse($this->can($target, $missing), 'an expired grant no longer applies');
        $panel = $this->svc()->panel($this->publicId($target), $super);
        self::assertCount(1, $panel['overrides']);
        self::assertSame(1, (int) $panel['overrides'][0]['expired'], 'but it is still listed, marked ended');

        self::assertSame(0, $this->svc()->pruneExpired(), 'kept for 30 days');
        $this->db->affectingStatement('UPDATE user_permissions SET expires_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE user_id = :u', ['u' => $target]);
        self::assertSame(1, $this->svc()->pruneExpired());
    }

    // ---- guard rails -----------------------------------------------------------------------------------------------

    public function test_the_rules_refuse_what_would_be_meaningless_or_dangerous(): void
    {
        [$held, $missing] = $this->heldAndMissing('read_only');
        $target = $this->publicId($this->user('read_only'));
        $super = $this->model($this->user('super_admin'));
        $svc = $this->svc();

        $refused = static function (callable $do, string $field) use ($svc): void {
            try {
                $do($svc);
                self::fail("expected a validation error on {$field}");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors());
            }
        };

        $refused(fn ($s) => $s->set($target, 'no.such_permission', 'allow', null, null, $super), 'permission');
        $refused(fn ($s) => $s->set($target, 'roles.manage', 'allow', null, null, $super), 'permission');
        $refused(fn ($s) => $s->set($target, $held, 'allow', null, null, $super), 'permission');      // the role already grants it
        $refused(fn ($s) => $s->set($target, $missing, 'deny', null, null, $super), 'permission');    // nothing to take away
        $refused(fn ($s) => $s->set($target, $missing, 'maybe', null, null, $super), 'effect');
        $refused(fn ($s) => $s->set($target, $missing, 'allow', null, str_repeat('x', 201), $super), 'note');
        foreach (['yesterday', '2030-13-45', '2030-02-30', gmdate('Y-m-d', strtotime('-1 day')), gmdate('Y-m-d', strtotime('+731 days')), '12/12/2030', "2030-01-01\n2030-01-02"] as $badDate) {
            $refused(fn ($s) => $s->set($target, $missing, 'allow', $badDate, null, $super), 'expires_on');
        }
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM user_permissions'), 'nothing was saved by any refused call');

        // the last valid day and a normal one are fine
        $svc->set($target, $missing, 'allow', gmdate('Y-m-d', strtotime('+730 days')), null, $super);
        $svc->set($target, $missing, 'allow', '', '  spaced note  ', $super);
        self::assertSame('spaced note', $this->db->selectValue('SELECT note FROM user_permissions LIMIT 1'));
    }

    public function test_who_may_be_changed_and_by_whom(): void
    {
        [, $missing] = $this->heldAndMissing('read_only');
        $superId = $this->user('super_admin');
        $super = $this->model($superId);
        $adminId = $this->user('admin');
        $other = $this->publicId($this->user('read_only'));

        foreach ([[$this->publicId($superId), 'SUPER_ADMIN_TARGET'], [$this->publicId($this->user('super_admin')), 'SUPER_ADMIN_TARGET']] as [$pid, $code]) {   // yourself included
            try {
                $this->svc()->set($pid, $missing, 'allow', null, null, $super);
                self::fail('should be refused');
            } catch (DomainRuleException $e) {
                self::assertSame($code, $e->ruleCode());
            }
        }
        try {
            $this->svc()->set($other, $missing, 'allow', null, null, $this->model($adminId));
            self::fail('a non-super admin cannot change permissions');
        } catch (DomainRuleException $e) {
            self::assertSame(403, $e->httpStatus());
        }
        try {
            $this->svc()->set('NOSUCHUSER000000000000000', $missing, 'allow', null, null, $super);
            self::fail();
        } catch (DomainRuleException $e) {
            self::assertSame(404, $e->httpStatus());
        }
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM user_permissions'));
    }

    public function test_a_person_can_hold_at_most_a_hundred_overrides(): void
    {
        $target = $this->user('read_only');
        $super = $this->model($this->user('super_admin'));
        $roleNames = $this->app->get(RoleAdminRepository::class)->grantedNames($this->roles['read_only']);
        $ids = array_column($this->db->select('SELECT id, name FROM permissions WHERE name <> :n ORDER BY id', ['n' => 'roles.manage']), 'id', 'name');
        $candidates = array_values(array_diff(array_keys($ids), $roleNames));
        self::assertGreaterThan(UserPermissionService::MAX_OVERRIDES, count($candidates) + 40, 'the catalogue is big enough to test the cap');
        // fill to the cap directly
        $n = 0;
        foreach (array_merge($candidates, $roleNames) as $name) {
            if ($n >= UserPermissionService::MAX_OVERRIDES) {
                break;
            }
            $this->db->insertRow('user_permissions', ['user_id' => $target, 'permission_id' => $ids[$name], 'effect' => in_array($name, $roleNames, true) ? 'deny' : 'allow']);
            $n++;
        }
        if ($n < UserPermissionService::MAX_OVERRIDES) {
            self::markTestSkipped('not enough permissions in the catalogue to reach the cap');
        }
        $free = array_values(array_diff(array_keys($ids), array_column($this->db->select('SELECT p.name FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = :u', ['u' => $target]), 'name'), $roleNames));
        if ($free === []) {
            self::markTestSkipped('no free permission left to attempt the 101st');
        }
        try {
            $this->svc()->set($this->publicId($target), $free[0], 'allow', null, null, $super);
            self::fail('the cap must hold');
        } catch (ValidationException $e) {
            self::assertStringContainsString((string) UserPermissionService::MAX_OVERRIDES, $e->errors()['permission'][0]);
        }
    }

    // ---- audit -----------------------------------------------------------------------------------------------------

    public function test_every_change_is_audited_with_old_and_new(): void
    {
        [$held, $missing] = $this->heldAndMissing('read_only');
        $target = $this->user('read_only');
        $super = $this->model($this->user('super_admin'));
        $pid = $this->publicId($target);

        $this->svc()->set($pid, $missing, 'allow', null, 'why', $super);
        $this->svc()->set($pid, $missing, 'allow', gmdate('Y-m-d', strtotime('+3 days')), 'why', $super);
        $this->svc()->remove($pid, $missing, $super);
        $this->svc()->set($pid, $held, 'deny', null, null, $super);
        $this->svc()->clear($pid, $super);

        $rows = $this->db->select("SELECT action, old_values, new_values, record_id FROM activity_logs WHERE module = 'users' AND action LIKE 'permission\\_override%' ORDER BY id");
        self::assertSame(['permission_override_set', 'permission_override_set', 'permission_override_removed', 'permission_override_set', 'permission_overrides_cleared'], array_column($rows, 'action'));
        self::assertSame($target, (int) $rows[0]['record_id']);
        self::assertNull($rows[0]['old_values']);
        self::assertStringContainsString('allow', (string) $rows[1]['old_values']);
        self::assertStringContainsString('until', (string) $rows[1]['new_values']);
    }

    // ---- over HTTP -------------------------------------------------------------------------------------------------

    public function test_only_the_super_admin_reaches_the_pages_and_writes_need_a_fresh_confirmation(): void
    {
        [, $missing] = $this->heldAndMissing('read_only');
        $target = $this->user('read_only');
        $pid = $this->publicId($target);

        $this->actAs($this->user('admin'));
        self::assertSame(403, $this->code('GET', "/admin/users/{$pid}/permissions"));
        self::assertSame(403, $this->code('POST', "/admin/users/{$pid}/permissions", ['permission' => $missing, 'effect' => 'allow']));
        self::assertSame(403, $this->code('POST', "/admin/users/{$pid}/permissions/reset", ['x' => '1']));

        $this->actAs($this->user('super_admin'), confirmed: false);
        $res = $this->send('POST', "/admin/users/{$pid}/permissions", ['permission' => $missing, 'effect' => 'allow']);
        self::assertStringContainsString('confirm', (string) $res->getHeader('Location'));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM user_permissions WHERE user_id = :u', ['u' => $target]));
    }

    public function test_the_super_admin_manages_overrides_from_the_page(): void
    {
        [$held, $missing] = $this->heldAndMissing('read_only');
        $target = $this->user('read_only');
        $pid = $this->publicId($target);
        $this->actAs($this->user('super_admin'));

        $page = $this->send('GET', "/admin/users/{$pid}/permissions");
        self::assertSame(200, $page->getStatus());
        self::assertStringContainsString('no-store', (string) $page->getHeader('Cache-Control'));
        self::assertStringContainsString('name="permission"', $page->getBody());
        self::assertStringContainsString($missing, $page->getBody());

        $res = $this->send('POST', "/admin/users/{$pid}/permissions", ['permission' => $missing, 'effect' => 'allow', 'expires_on' => gmdate('Y-m-d', strtotime('+7 days')), 'note' => 'cover']);
        self::assertSame("/admin/users/{$pid}/permissions", $res->getHeader('Location'));
        self::assertTrue($this->can($target, $missing));
        $listed = $this->send('GET', "/admin/users/{$pid}/permissions")->getBody();
        self::assertStringContainsString('cover', $listed);
        self::assertStringContainsString('Remove all overrides', $listed);

        $this->send('POST', "/admin/users/{$pid}/permissions", ['permission' => $held, 'effect' => 'allow']);   // refused: role already has it
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM user_permissions WHERE user_id = :u', ['u' => $target]));

        $this->send('POST', "/admin/users/{$pid}/permissions/remove", ['permission' => $missing]);
        self::assertFalse($this->can($target, $missing));

        $this->send('POST', "/admin/users/{$pid}/permissions", ['permission' => $held, 'effect' => 'deny']);
        $this->send('POST', "/admin/users/{$pid}/permissions/reset", ['x' => '1']);
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM user_permissions WHERE user_id = :u', ['u' => $target]));

        self::assertSame(404, $this->code('GET', '/admin/users/NOSUCHUSER000000000000000/permissions'));
    }

    public function test_a_super_admin_target_shows_no_form_and_cannot_be_changed(): void
    {
        [, $missing] = $this->heldAndMissing('read_only');
        $me = $this->user('super_admin');
        $peer = $this->user('super_admin');
        $this->actAs($me);

        foreach ([$this->publicId($peer), $this->publicId($me)] as $pid) {
            $page = $this->send('GET', "/admin/users/{$pid}/permissions");
            self::assertSame(200, $page->getStatus());
            self::assertStringNotContainsString('name="permission"', $page->getBody(), 'no form for someone who cannot be changed');
            $this->send('POST', "/admin/users/{$pid}/permissions", ['permission' => $missing, 'effect' => 'allow']);
        }
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM user_permissions'));

        // the user page offers the button for a normal person only
        $normal = $this->publicId($this->user('accounts'));
        self::assertStringContainsString('Individual permissions', $this->send('GET', "/admin/users/{$normal}")->getBody());
        self::assertStringNotContainsString('Individual permissions', $this->send('GET', '/admin/users/' . $this->publicId($peer))->getBody());
    }
}
