<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\UserRepository;
use App\Services\GlobalSearchService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Global search: it finds what the person may see, only that, and only with the module's own scoping. */
final class GlobalSearchTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->branchA = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'GS A', 'code' => 'GSX-A' . bin2hex(random_bytes(2))]);
        $this->branchB = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'GS B', 'code' => 'GSX-B' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM public_enquiries WHERE name LIKE 'GS %'");
        $this->db->affectingStatement('DELETE FROM leads WHERE branch_id IN (?, ?)', [$this->branchA, $this->branchB]);
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement('DELETE FROM branches WHERE id IN (?, ?)', [$this->branchA, $this->branchB]);
    }

    private function user(string $role, ?int $branch = null): int
    {
        $branch ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "GS {$role}", 'email' => 'gs_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function lead(string $name, int $branch, string $phone): string
    {
        $pid = Ulid::generate();
        $this->db->insertRow('leads', [
            'public_id' => $pid, 'lead_number' => 'LEAD-GS-' . random_int(100000, 999999), 'branch_id' => $branch, 'name' => $name, 'phone' => $phone,
            'status_id' => (int) $this->db->selectValue('SELECT MIN(id) FROM lead_statuses'),
        ]);

        return $pid;
    }

    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId, '_auth_at' => time()] : []) + ['_token' => bin2hex(random_bytes(32)), '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    private function search(string $q): Response
    {
        return $this->router->dispatch(new Request(['q' => $q], [], ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/search', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
        ], ''));
    }

    public function test_it_finds_by_name_phone_and_number_within_my_branch_only(): void
    {
        $mine = $this->lead('GS Zoltan Mine', $this->branchA, '9811122233');
        $theirs = $this->lead('GS Zoltan Theirs', $this->branchB, '9811122244');
        $number = (string) $this->db->selectValue('SELECT lead_number FROM leads WHERE public_id = ?', [$mine]);
        $this->actAs($this->user('counselor'));

        $byName = $this->search('Zoltan')->getBody();
        self::assertStringContainsString('GS Zoltan Mine', $byName);
        self::assertStringContainsString('/leads/' . $mine, $byName);
        self::assertStringNotContainsString('GS Zoltan Theirs', $byName, 'another branch is invisible');
        self::assertStringNotContainsString($theirs, $byName);

        self::assertStringContainsString('GS Zoltan Mine', $this->search('98111222')->getBody(), 'phone prefix');
        self::assertStringContainsString('GS Zoltan Mine', $this->search($number)->getBody(), 'lead number');
    }

    public function test_an_org_wide_administrator_sees_every_branch(): void
    {
        $this->lead('GS Ulrich Mine', $this->branchA, '9822200001');
        $this->lead('GS Ulrich Theirs', $this->branchB, '9822200002');
        $admin = $this->user('admin');
        $this->db->affectingStatement('UPDATE users SET is_org_wide = 1 WHERE id = ?', [$admin]);
        $this->actAs($admin);

        $html = $this->search('Ulrich')->getBody();

        self::assertStringContainsString('GS Ulrich Mine', $html);
        self::assertStringContainsString('GS Ulrich Theirs', $html);
    }

    public function test_sections_follow_permissions(): void
    {
        $this->db->insertRow('public_enquiries', ['type' => 'contact', 'name' => 'GS Enquirer Yolanda', 'phone' => '9833300001', 'status' => 'new', 'meta_json' => '{}']);

        $this->actAs($this->user('manager'));
        self::assertStringContainsString('Website enquiries', $this->search('Yolanda')->getBody());

        $this->actAs($this->user('accounts'));
        $html = $this->search('Yolanda')->getBody();
        self::assertStringNotContainsString('Website enquiries', $html, 'accounts cannot see the inbox');
        self::assertStringNotContainsString('GS Enquirer Yolanda', $html);
    }

    public function test_short_empty_and_hostile_queries_are_handled(): void
    {
        $this->lead('GS Xavier', $this->branchA, '9844400001');
        $this->actAs($this->user('counselor'));

        self::assertStringContainsString('Search everything', $this->search('')->getBody());
        self::assertStringContainsString('Enter at least', $this->search('x')->getBody());
        self::assertStringContainsString('No results', $this->search('zzz-no-such-thing')->getBody());

        $xss = $this->search('<script>alert(1)</script>')->getBody();
        self::assertStringNotContainsString('<script>alert(1)</script>', $xss);
        self::assertSame(200, $this->search("'; DROP TABLE leads;--")->getStatus());
        self::assertSame(200, $this->search('%_%')->getStatus(), 'LIKE wildcards are literal');
        self::assertStringNotContainsString('GS Xavier', $this->search('%%')->getBody(), '% is not "match everything"');
    }

    public function test_the_query_is_normalised_and_capped(): void
    {
        self::assertSame('a b c', GlobalSearchService::normalise("  a \n\t b   c "));
        self::assertSame(60, mb_strlen(GlobalSearchService::normalise(str_repeat('é', 200))));
    }

    public function test_more_than_five_hits_link_to_the_full_list(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->lead("GS Bulkname {$i}", $this->branchA, '98555000' . $i . '0');
        }
        $this->actAs($this->user('counselor'));

        $html = $this->search('Bulkname')->getBody();

        self::assertSame(5, substr_count($html, 'GS Bulkname'));
        self::assertStringContainsString('See all 7', $html);
        self::assertStringContainsString('/leads?q=Bulkname', $html);
    }

    public function test_the_topbar_has_a_search_box_and_anonymous_users_are_sent_to_sign_in(): void
    {
        $this->actAs($this->user('counselor'));
        $page = $this->router->dispatch(new Request([], [], ['crm_session' => $this->sid], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'], ''))->getBody();
        self::assertStringContainsString('role="search"', $page);
        self::assertStringContainsString('name="q"', $page);

        $this->actAs(null);
        self::assertSame(302, $this->search('anything')->getStatus());
    }
}
