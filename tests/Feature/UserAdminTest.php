<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\UserAdminService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Users: the screens, and above all the rules that stop privilege escalation and lock-out. */
final class UserAdminTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private string $token = '';
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> super admins we deactivated for a test and must put back */
    private array $paused = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->branchA = $this->branch('UAX-A');
        $this->branchB = $this->branch('UAX-B');
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        foreach ($this->paused as $id) {
            $this->db->affectingStatement('UPDATE users SET is_active = 1 WHERE id = ?', [$id]);
        }
        $this->db->affectingStatement("DELETE FROM users WHERE email LIKE 'ua\\_%@dev.local'");   // created through the screens (deleted below with the rest)
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'users' AND action LIKE 'user\\_%'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['UAX-%']);
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2))]);
    }

    private function user(string $role, array $over = []): int
    {
        $id = (int) $this->db->insertRow('users', $over + [
            'public_id' => Ulid::generate(), 'name' => "UA {$role}", 'email' => 'ua_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branchA, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branchA]);
        $this->userIds[] = $id;

        return $id;
    }

    private function model(int $id): User
    {
        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function publicId(int $id): string
    {
        return (string) $this->db->selectValue('SELECT public_id FROM users WHERE id = ?', [$id]);
    }

    private function service(): UserAdminService
    {
        return $this->app->get(UserAdminService::class);
    }

    /** @param array<string,mixed> $over */
    private function input(array $over = []): array
    {
        return $over + ['name' => 'UA New Person', 'email' => 'ua_' . bin2hex(random_bytes(4)) . '@dev.local', 'phone' => '+91 98765 43210',
            'role_id' => $this->roles['counselor'], 'branch_ids' => [$this->branchA], 'primary_branch_id' => (string) $this->branchA, 'is_org_wide' => ''];
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

    // ---- creating ------------------------------------------------------------------------------

    public function test_creating_a_user_sets_a_one_time_password_branches_and_an_audit_row_without_secrets(): void
    {
        $admin = $this->model($this->user('admin'));
        $in = $this->input(['branch_ids' => [$this->branchA, $this->branchB], 'primary_branch_id' => (string) $this->branchB]);

        $r = $this->service()->create($in, $admin);

        $row = $this->db->selectOne('SELECT * FROM users WHERE public_id = ?', [$r['public_id']]);
        $this->userIds[] = (int) $row['id'];
        self::assertSame(1, (int) $row['must_change_password']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame($this->branchB, (int) $row['primary_branch_id']);
        self::assertSame(strtolower($in['email']), $row['email']);
        self::assertTrue($this->app->get(Hash::class)->verify($r['temporary_password'], $row['password_hash']));
        self::assertMatchesRegularExpression('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)[A-Za-z0-9]{14}$/', $r['temporary_password']);
        self::assertSame([$this->branchA, $this->branchB], array_map('intval', array_column($this->db->select('SELECT branch_id FROM user_branches WHERE user_id = ? ORDER BY branch_id', [$row['id']]), 'branch_id')));

        $log = (string) $this->db->selectValue("SELECT new_values FROM activity_logs WHERE action = 'user_created' AND record_id = ?", [$row['id']]);
        self::assertStringContainsString($in['email'], $log);
        self::assertStringNotContainsString($r['temporary_password'], $log);
        self::assertStringNotContainsString('password', strtolower($log));
    }

    public function test_creating_validates_and_rejects_duplicates(): void
    {
        $admin = $this->model($this->user('admin'));
        $existing = (string) $this->db->selectValue('SELECT email FROM users WHERE id = ?', [$this->userIds[0]]);

        foreach ([
            'name' => ['name' => ' '], 'email' => ['email' => 'not-an-email'], 'phone' => ['phone' => 'call me'], 'role' => ['role_id' => 0],
            'branch' => ['branch_ids' => [], 'primary_branch_id' => ''], 'ghost branch' => ['branch_ids' => [999999999], 'primary_branch_id' => ''],
        ] as $label => $bad) {
            try {
                $this->service()->create($this->input($bad), $admin);
                self::fail("accepted invalid {$label}");
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors(), $label);
            }
        }
        try {
            $this->service()->create($this->input(['email' => strtoupper($existing)]), $admin);
            self::fail('accepted a duplicate email');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
        }
    }

    public function test_the_primary_branch_is_always_one_of_the_users_branches(): void
    {
        $admin = $this->model($this->user('admin'));
        $r = $this->service()->create($this->input(['branch_ids' => [$this->branchA], 'primary_branch_id' => (string) $this->branchB]), $admin);
        $id = (int) $this->db->selectValue('SELECT id FROM users WHERE public_id = ?', [$r['public_id']]);
        $this->userIds[] = $id;

        self::assertSame([$this->branchA, $this->branchB], array_map('intval', array_column($this->db->select('SELECT branch_id FROM user_branches WHERE user_id = ? ORDER BY branch_id', [$id]), 'branch_id')));
    }

    // ---- privilege rules -------------------------------------------------------------------------

    public function test_only_a_super_admin_can_grant_or_touch_the_super_admin_role(): void
    {
        $admin = $this->model($this->user('admin'));
        $super = $this->model($this->user('super_admin'));
        $victim = $this->publicId($this->user('super_admin'));

        $this->refused(fn () => $this->service()->create($this->input(['role_id' => $this->roles['super_admin']]), $admin), 'USER_PRIVILEGE');
        $this->refused(fn () => $this->service()->update($victim, $this->input(['role_id' => $this->roles['counselor']]), $admin), 'USER_PRIVILEGE');
        $this->refused(fn () => $this->service()->setActive($victim, false, $admin), 'USER_PRIVILEGE');
        $this->refused(fn () => $this->service()->issueTemporaryPassword($victim, $admin), 'USER_PRIVILEGE');
        $this->refused(fn () => $this->service()->resetTwoFactor($victim, $admin), 'USER_PRIVILEGE');

        $made = $this->service()->create($this->input(['role_id' => $this->roles['super_admin']]), $super);   // a super admin may
        $this->userIds[] = (int) $this->db->selectValue('SELECT id FROM users WHERE public_id = ?', [$made['public_id']]);
        self::assertSame('super_admin', $this->db->selectValue('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.public_id = ?', [$made['public_id']]));
    }

    public function test_only_administrators_can_make_someone_organisation_wide(): void
    {
        $manager = $this->model($this->user('manager'));
        $admin = $this->model($this->user('admin'));

        $this->refused(fn () => $this->service()->create($this->input(['is_org_wide' => '1']), $manager), 'USER_PRIVILEGE');

        $r = $this->service()->create($this->input(['is_org_wide' => '1', 'branch_ids' => [], 'primary_branch_id' => '']), $admin);
        $this->userIds[] = (int) $this->db->selectValue('SELECT id FROM users WHERE public_id = ?', [$r['public_id']]);
        self::assertSame(1, (int) $this->db->selectValue('SELECT is_org_wide FROM users WHERE public_id = ?', [$r['public_id']]));
    }

    public function test_nobody_can_lock_themselves_out(): void
    {
        $meId = $this->user('admin');
        $me = $this->model($meId);

        $this->refused(fn () => $this->service()->setActive($this->publicId($meId), false, $me), 'USER_SELF');
        $this->refused(fn () => $this->service()->update($this->publicId($meId), $this->input(['name' => 'UA Me', 'role_id' => $this->roles['counselor']]), $me), 'USER_SELF');
        $this->refused(fn () => $this->service()->issueTemporaryPassword($this->publicId($meId), $me), 'USER_SELF');

        // renaming yourself is fine
        $this->service()->update($this->publicId($meId), $this->input(['name' => 'UA Me Renamed', 'role_id' => $this->roles['admin']]), $me);
        self::assertSame('UA Me Renamed', $this->db->selectValue('SELECT name FROM users WHERE id = ?', [$meId]));
    }

    public function test_the_last_active_super_admin_cannot_be_deactivated_or_demoted(): void
    {
        $others = array_map('intval', array_column($this->db->select("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'super_admin' AND u.is_active = 1"), 'id'));
        $actorId = $this->user('super_admin');
        $lastId = $this->user('super_admin');
        // make $lastId the only active super admin besides the actor, then the actor also steps aside
        foreach ($others as $id) {
            if (!in_array($id, [$actorId, $lastId], true)) {
                $this->db->affectingStatement('UPDATE users SET is_active = 0 WHERE id = ?', [$id]);
                $this->paused[] = $id;
            }
        }
        $actor = $this->model($actorId);
        $last = $this->publicId($lastId);
        $this->db->affectingStatement('UPDATE users SET is_active = 0 WHERE id = ?', [$actorId]);   // now $lastId is the last one standing
        $this->paused[] = $actorId;

        $this->refused(fn () => $this->service()->setActive($last, false, $actor), 'USER_LAST_SUPER');
        $this->refused(fn () => $this->service()->update($last, $this->input(['role_id' => $this->roles['admin']]), $actor), 'USER_LAST_SUPER');
        self::assertSame(1, (int) $this->db->selectValue('SELECT is_active FROM users WHERE id = ?', [$lastId]));
    }

    // ---- account actions ----------------------------------------------------------------------------

    public function test_deactivating_signs_the_person_out_everywhere_and_reactivating_restores_them(): void
    {
        $admin = $this->model($this->user('admin'));
        $targetId = $this->user('counselor');
        $this->db->insertRow('sessions', ['id' => bin2hex(random_bytes(32)), 'user_id' => $targetId, 'payload' => 'x', 'last_activity' => time(), 'created_at' => gmdate('Y-m-d H:i:s')]);

        $this->service()->setActive($this->publicId($targetId), false, $admin);

        self::assertSame(0, (int) $this->db->selectValue('SELECT is_active FROM users WHERE id = ?', [$targetId]));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$targetId]));
        self::assertNull($this->app->get(UserRepository::class)->findActiveById($targetId), 'a deactivated account cannot resolve as a signed-in user');
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE action = 'user_deactivated' AND record_id = ?", [$targetId]));

        $this->service()->setActive($this->publicId($targetId), true, $admin);
        self::assertNotNull($this->app->get(UserRepository::class)->findActiveById($targetId));
    }

    public function test_unlock_and_temporary_password_and_two_factor_reset(): void
    {
        $admin = $this->model($this->user('admin'));
        $targetId = $this->user('counselor', ['failed_login_count' => 7, 'locked_until' => gmdate('Y-m-d H:i:s', time() + 3600), 'two_factor_enabled' => 1]);
        $pid = $this->publicId($targetId);
        $oldHash = (string) $this->db->selectValue('SELECT password_hash FROM users WHERE id = ?', [$targetId]);

        $this->service()->unlock($pid, $admin);
        $row = $this->db->selectOne('SELECT failed_login_count, locked_until FROM users WHERE id = ?', [$targetId]);
        self::assertSame(0, (int) $row['failed_login_count']);
        self::assertNull($row['locked_until']);

        $temp = $this->service()->issueTemporaryPassword($pid, $admin);
        $new = $this->db->selectOne('SELECT password_hash, must_change_password FROM users WHERE id = ?', [$targetId]);
        self::assertNotSame($oldHash, $new['password_hash']);
        self::assertSame(1, (int) $new['must_change_password']);
        self::assertTrue($this->app->get(Hash::class)->verify($temp, $new['password_hash']));

        $this->service()->resetTwoFactor($pid, $admin);
        self::assertSame(0, (int) $this->db->selectValue('SELECT two_factor_enabled FROM users WHERE id = ?', [$targetId]));
    }

    // ---- the screens --------------------------------------------------------------------------------

    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId, '_auth_at' => time()] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    private function send(string $method, string $uri, array $post = [], array $query = []): Response
    {
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }

        return $this->router->dispatch(new Request($query, $post, ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    private function code(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->send($method, $uri, $post)->getStatus();
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        } catch (\App\Exceptions\AuthorizationException) {
            return 403;
        }
    }

    public function test_only_people_with_the_permission_reach_the_screens(): void
    {
        $target = $this->publicId($this->user('counselor'));

        $this->actAs($this->user('counselor'));
        self::assertSame(403, $this->code('GET', '/admin/users'));
        self::assertSame(403, $this->code('GET', "/admin/users/{$target}"));
        self::assertSame(403, $this->code('POST', "/admin/users/{$target}/status", ['active' => '0']));

        $this->actAs($this->user('admin'));
        self::assertSame(200, $this->code('GET', '/admin/users'));
        self::assertSame(200, $this->code('GET', '/admin/users/create'));
        self::assertSame(200, $this->code('GET', "/admin/users/{$target}"));
        self::assertSame(200, $this->code('GET', "/admin/users/{$target}/edit"));
        self::assertSame(404, $this->code('GET', '/admin/users/no-such-user'));

        $this->actAs(null);
        self::assertSame(302, $this->code('GET', '/admin/users'));
    }

    public function test_the_list_filters_searches_and_escapes(): void
    {
        $this->user('counselor', ['name' => 'UA Filter Counselor <b>x</b>']);
        $this->user('accounts', ['name' => 'UA Filter Accounts', 'is_active' => 0]);
        $this->actAs($this->user('admin'));

        $all = $this->send('GET', '/admin/users', [], ['q' => 'UA Filter'])->getBody();
        self::assertStringContainsString('UA Filter Accounts', $all);
        self::assertStringNotContainsString('<b>x</b>', $all);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $all);

        $inactive = $this->send('GET', '/admin/users', [], ['q' => 'UA Filter', 'status' => 'inactive'])->getBody();
        self::assertStringContainsString('UA Filter Accounts', $inactive);
        self::assertStringNotContainsString('UA Filter Counselor', $inactive);

        $role = $this->send('GET', '/admin/users', [], ['q' => 'UA Filter', 'role' => 'counselor'])->getBody();
        self::assertStringContainsString('UA Filter Counselor', $role);
        self::assertStringNotContainsString('UA Filter Accounts', $role);
    }

    public function test_creating_through_the_screen_shows_the_temporary_password_exactly_once(): void
    {
        $this->actAs($this->user('admin'));
        $email = 'ua_' . bin2hex(random_bytes(4)) . '@dev.local';

        $res = $this->send('POST', '/admin/users', ['name' => 'UA Screen Person', 'email' => $email, 'role_id' => (string) $this->roles['counselor'], 'branch_ids' => [(string) $this->branchA], 'primary_branch_id' => (string) $this->branchA]);

        self::assertSame(302, $res->getStatus());
        $location = (string) $res->getHeader('Location');
        self::assertStringStartsWith('/admin/users/', $location);
        $first = $this->send('GET', $location)->getBody();
        self::assertStringContainsString('Temporary password', $first);
        self::assertMatchesRegularExpression('/copy it now\): [A-Za-z0-9]{14}/', $first);
        self::assertStringNotContainsString('Temporary password', $this->send('GET', $location)->getBody(), 'gone after the first view');
        $this->userIds[] = (int) $this->db->selectValue('SELECT id FROM users WHERE email = ?', [$email]);
    }

    public function test_a_super_admin_account_is_read_only_for_a_plain_admin(): void
    {
        $super = $this->publicId($this->user('super_admin'));
        $this->actAs($this->user('admin'));

        $html = $this->send('GET', "/admin/users/{$super}")->getBody();
        self::assertStringContainsString('You cannot change this account', $html);
        self::assertStringNotContainsString('Deactivate account', $html);

        $this->send('POST', "/admin/users/{$super}/status", ['active' => '0']);
        self::assertSame(1, (int) $this->db->selectValue('SELECT is_active FROM users WHERE public_id = ?', [$super]));
    }

    public function test_the_admin_menu_link_now_leads_somewhere(): void
    {
        $this->actAs($this->user('admin'));

        self::assertStringContainsString('href="/admin/users"', $this->send('GET', '/admin/users')->getBody());
        self::assertSame(200, $this->code('GET', '/admin/users'));
    }
}
