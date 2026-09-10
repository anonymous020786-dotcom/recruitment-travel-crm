<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Http\Request;
use App\Http\Router;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/**
 * Exercises LeadController through the real router + middleware stack
 * (session + csrf + auth + branch + can:) against seeded permissions on MariaDB.
 */
final class LeadControllerTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private array $roles = [];
    private int $branch;
    private array $cleanupUsers = [];
    private array $cleanupBranches = [];
    private string $sid = '';
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->db->exists('SELECT 1 FROM role_permissions LIMIT 1')
            || (int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->branch = (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => 'LC Branch', 'code' => 'LC-' . bin2hex(random_bytes(3)),
        ]);

        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);

        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module='leads'");
        $this->db->affectingStatement('DELETE FROM leads WHERE branch_id = ?', [$this->branch]);
        if ($this->cleanupUsers !== []) {
            $ph = implode(',', array_fill(0, count($this->cleanupUsers), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->cleanupUsers);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->cleanupUsers);
        }
        foreach (array_merge([$this->branch], $this->cleanupBranches) as $b) {
            $this->db->affectingStatement('DELETE FROM branches WHERE id = ?', [$b]);
        }
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%'");
    }

    private function newUser(string $role, ?int $branchId = null): int
    {
        $branchId ??= $this->branch;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(),
            'name' => "LC {$role}",
            'email' => 'lc_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role],
            'primary_branch_id' => $branchId,
            'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->cleanupUsers[] = $id;

        return $id;
    }

    /** Seed a pre-authenticated session in the store and remember its id + token. */
    private function actAs(int $userId): User
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => [
            '_auth_user_id' => $userId,
            '_token' => $this->token,
            '_started_at' => time(),
            '_last_regen' => time(),
            '_last_activity' => time(),
        ], 'touched' => time()];

        $this->app->instance(Auth::class, new Auth($this->app, new UserRepository($this->db)));

        return $this->app->get(UserRepository::class)->findById($userId);
    }

    private function request(string $method, string $uri, array $post = []): Request
    {
        $cookies = $this->sid !== '' ? ['crm_session' => $this->sid] : [];
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }

        return new Request([], $post, $cookies, [], [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], '');
    }

    private function hit(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->router->dispatch($this->request($method, $uri, $post))->getStatus();
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        } catch (\App\Exceptions\AuthorizationException) {
            return 403;
        }
    }

    public function test_unauthenticated_is_redirected(): void
    {
        self::assertSame(302, $this->hit('GET', '/leads'));
    }

    public function test_read_only_role_cannot_create(): void
    {
        $this->actAs($this->newUser('read_only'));
        self::assertSame(200, $this->hit('GET', '/leads'));
        self::assertSame(403, $this->hit('GET', '/leads/create'));
    }

    public function test_counselor_can_list_and_open_create(): void
    {
        $this->actAs($this->newUser('counselor'));
        self::assertSame(200, $this->hit('GET', '/leads'));
        self::assertSame(200, $this->hit('GET', '/leads/create'));
    }

    public function test_create_validation_error_redirects_back(): void
    {
        $this->actAs($this->newUser('manager'));
        self::assertSame(302, $this->hit('POST', '/leads', ['name' => '', 'phone' => 'x', 'priority' => 'medium']));
        self::assertArrayHasKey('name', errors());
    }

    public function test_create_success_then_show_and_scope_isolation(): void
    {
        $this->actAs($this->newUser('manager'));

        $res = $this->router->dispatch($this->request('POST', '/leads', [
            'name' => 'Priya N', 'phone' => '9871234500', 'priority' => 'medium',
        ]));
        self::assertSame(302, $res->getStatus());
        $location = (string) $res->getHeader('Location');
        self::assertStringStartsWith('/leads/', $location);
        self::assertSame(200, $this->hit('GET', $location));

        // Manager in another branch → 404 (not found in scope).
        $otherBranch = (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => 'Other', 'code' => 'LC-O-' . bin2hex(random_bytes(2)),
        ]);
        $this->cleanupBranches[] = $otherBranch;
        $this->actAs($this->newUser('manager', $otherBranch));
        self::assertSame(404, $this->hit('GET', $location));
    }
}
