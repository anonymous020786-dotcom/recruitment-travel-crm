<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** The audit-log viewer: filters, branch scoping of what a viewer may see, presentation, and access. */
final class AuditLogTest extends DbTestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->branchA = $this->branch('AUX-A');
        $this->branchB = $this->branch('AUX-B');
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module LIKE 'aux\\_%'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['AUX-%']);
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2))]);
    }

    private function user(string $role, ?int $branch = null, string $name = 'AU person'): int
    {
        $branch ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => $name, 'email' => 'au_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function model(int $id): User
    {
        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function service(): AuditLogService
    {
        return $this->app->get(AuditLogService::class);
    }

    /** @param array<string,mixed> $over */
    private function entry(?int $userId, array $over = []): int
    {
        return (int) $this->db->insertRow('activity_logs', $over + [
            'user_id' => $userId, 'action' => 'aux_action', 'module' => 'aux_mod', 'record_type' => 'aux_thing', 'record_id' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<int> ids of our test rows the viewer's page contains */
    private function visibleTo(User $viewer, array $input = []): array
    {
        $filters = $this->service()->filters($input + ['module' => 'aux_mod']);
        $ids = [];
        foreach ($this->service()->page($viewer, $filters, 1)['rows'] as $r) {
            $ids[] = (int) $r['id'];
        }
        sort($ids);

        return $ids;
    }

    // ---- filters ---------------------------------------------------------------------------------------------------

    public function test_filters_default_to_the_last_thirty_days_and_are_normalised(): void
    {
        $f = $this->service()->filters([]);
        self::assertSame(gmdate('Y-m-d'), $f['to']);
        self::assertSame(gmdate('Y-m-d', strtotime('-29 days')), $f['from']);
        self::assertSame(['', '', '', ''], [$f['module'], $f['q'], $f['record_type'], $f['record_id']]);

        $f = $this->service()->filters(['module' => ' Invoices ', 'q' => str_repeat('q', 200), 'from' => '2026-01-01', 'to' => '2026-01-31', 'record_type' => 'Invoice', 'record_id' => '42']);
        self::assertSame(['invoices', 80, '2026-01-01', '2026-01-31', 'invoice', '42'], [$f['module'], strlen($f['q']), $f['from'], $f['to'], $f['record_type'], $f['record_id']]);
    }

    public function test_bad_filters_are_rejected_field_by_field(): void
    {
        foreach ([
            'from' => ['from' => '31/01/2026'], 'impossible date' => ['from' => '2026-02-30'], 'order' => ['from' => '2026-02-01', 'to' => '2026-01-01'],
            'span' => ['from' => '2020-01-01', 'to' => '2026-01-01'], 'module' => ['module' => 'a b; DROP TABLE x'], 'record type' => ['record_type' => "x'--"],
            'record id' => ['record_id' => '12abc'],
        ] as $label => $bad) {
            try {
                $this->service()->filters($bad);
                self::fail("accepted {$label}");
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors(), $label);
            }
        }
    }

    // ---- who sees what -------------------------------------------------------------------------------------------------

    public function test_organisation_wide_viewers_see_everything_and_branch_viewers_only_their_branchs_people(): void
    {
        $inA = $this->user('counselor', $this->branchA);
        $inB = $this->user('counselor', $this->branchB);
        $a = $this->entry($inA);
        $b = $this->entry($inB);
        $system = $this->entry(null);

        $admin = $this->model($this->user('admin', $this->branchB));
        self::assertSame([$a, $b, $system], $this->visibleTo($admin), 'admins are organisation-wide');

        $managerA = $this->model($this->user('manager', $this->branchA));
        self::assertSame([$a], $this->visibleTo($managerA), 'a branch manager sees only actions by people in their branch — not other branches, not system rows');
    }

    public function test_filters_narrow_the_rows(): void
    {
        $person = $this->user('counselor', $this->branchA, 'AU Zebra Person');
        $other = $this->user('counselor', $this->branchA, 'AU Other');
        $this->entry($person, ['action' => 'invoice_voided', 'module' => 'aux_fin', 'record_type' => 'aux_invoice', 'record_id' => 7, 'context' => 'customer asked']);
        $this->entry($other, ['action' => 'lead_created', 'module' => 'aux_mod', 'record_type' => 'aux_lead', 'record_id' => 9]);
        $old = $this->entry($other, ['action' => 'ancient', 'module' => 'aux_mod', 'created_at' => '2025-01-01 10:00:00']);
        $admin = $this->model($this->user('admin'));
        $rows = fn (array $in): array => array_column($this->service()->page($admin, $this->service()->filters($in + ['module' => '']), 1)['rows'], 'action');
        $mine = static fn (array $actions): array => array_values(array_intersect($actions, ['invoice_voided', 'lead_created', 'ancient']));

        self::assertSame(['invoice_voided'], $mine($rows(['module' => 'aux_fin'])));
        self::assertSame(['invoice_voided'], $mine($rows(['q' => 'zebra'])), 'search matches the person');
        self::assertSame(['invoice_voided'], $mine($rows(['q' => 'asked'])), 'and the note');
        self::assertSame(['lead_created'], $mine($rows(['record_type' => 'aux_lead', 'record_id' => '9'])));
        self::assertSame([], $mine($rows(['record_type' => 'aux_lead', 'record_id' => '10'])));
        self::assertNotContains('ancient', $rows([]), 'the default window is 30 days');
        self::assertSame(['ancient'], $mine($rows(['from' => '2025-01-01', 'to' => '2025-01-02'])));
        self::assertSame(['ancient'], $mine($rows(['q' => 'ancient', 'from' => '2025-01-01', 'to' => '2025-06-01'])));
        self::assertGreaterThan(0, $old);

        // a % or _ typed by the user is a literal character, not a wildcard
        self::assertSame([], $mine($rows(['q' => '%'])));
    }

    public function test_rows_are_newest_first_and_paged(): void
    {
        $u = $this->user('counselor');
        $first = $this->entry($u, ['created_at' => gmdate('Y-m-d H:i:s', time() - 300)]);
        $second = $this->entry($u, ['created_at' => gmdate('Y-m-d H:i:s', time() - 100)]);
        $admin = $this->model($this->user('admin'));

        $page = $this->service()->page($admin, $this->service()->filters(['module' => 'aux_mod']), 1);

        self::assertSame([$second, $first], array_map(static fn (array $r): int => (int) $r['id'], $page['rows']));
        self::assertSame(2, $page['total']);
        self::assertFalse($page['capped']);
        self::assertSame([], $this->service()->page($admin, $this->service()->filters(['module' => 'aux_mod']), 2)['rows']);
    }

    // ---- presentation --------------------------------------------------------------------------------------------------

    public function test_rows_carry_a_readable_ip_and_decoded_before_after_values(): void
    {
        $u = $this->user('counselor');
        $this->entry($u, ['ip_address' => inet_pton('203.0.113.7'), 'old_values' => json_encode(['status' => 'draft']), 'new_values' => json_encode(['status' => 'issued', 'total' => '100.00'])]);
        $this->entry($u, ['ip_address' => inet_pton('2001:db8::1'), 'new_values' => json_encode(['note' => str_repeat('x', 9000)])]);
        $admin = $this->model($this->user('admin'));

        $rows = $this->service()->page($admin, $this->service()->filters(['module' => 'aux_mod']), 1)['rows'];
        $byIp = array_column($rows, null, 'ip');

        self::assertArrayHasKey('203.0.113.7', $byIp);
        self::assertStringContainsString('"status": "draft"', (string) $byIp['203.0.113.7']['old_pretty']);
        self::assertStringContainsString('"total": "100.00"', (string) $byIp['203.0.113.7']['new_pretty']);
        self::assertArrayNotHasKey('ip_address', $rows[0], 'the raw binary is not passed to the view');
        self::assertStringContainsString('truncated', (string) $byIp['2001:db8::1']['new_pretty']);
        self::assertLessThan(6100, strlen((string) $byIp['2001:db8::1']['new_pretty']));
    }

    // ---- the screen -------------------------------------------------------------------------------------------------------

    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => time()] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    /** @param array<string,string> $query */
    private function get(string $uri, array $query = []): Response
    {
        return $this->router->dispatch(new Request($query, [], ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    private function code(string $uri, array $query = []): int
    {
        try {
            return $this->get($uri, $query)->getStatus();
        } catch (AuthorizationException) {
            return 403;
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_only_people_with_audit_view_reach_the_screen(): void
    {
        $this->actAs($this->user('counselor'));
        self::assertSame(403, $this->code('/admin/audit'));

        $this->actAs(null);
        self::assertSame(302, $this->code('/admin/audit'));

        $this->actAs($this->user('manager'));
        self::assertSame(200, $this->code('/admin/audit'));
        $this->actAs($this->user('admin'));
        self::assertSame(200, $this->code('/admin/audit'));
    }

    public function test_the_screen_shows_entries_escaped_and_explains_bad_filters(): void
    {
        $u = $this->user('counselor', $this->branchA, 'AU <b>Bold</b> Name');
        $this->entry($u, ['action' => 'aux_did_thing', 'context' => '<script>alert(1)</script>', 'new_values' => json_encode(['x' => '<img src=x onerror=alert(1)>']), 'ip_address' => inet_pton('198.51.100.9')]);
        $this->actAs($this->user('admin'));

        $page = $this->get('/admin/audit', ['module' => 'aux_mod'])->getBody();

        self::assertStringContainsString('Aux Did Thing', $page);
        self::assertStringContainsString('198.51.100.9', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        self::assertStringContainsString('AU &lt;b&gt;Bold&lt;/b&gt; Name', $page);
        self::assertStringNotContainsString('<script>alert(1)', $page);
        self::assertStringNotContainsString('<img src=x', $page);
        self::assertStringContainsString('href="/admin/audit?record_type=aux_thing', $page, 'a record id links to that record\'s history');

        $bad = $this->get('/admin/audit', ['from' => 'yesterday'])->getBody();
        self::assertStringContainsString('Some filters were not valid', $bad);
        self::assertStringContainsString('Audit log', $bad);
    }

    public function test_the_viewer_cannot_change_the_log(): void
    {
        $this->actAs($this->user('admin'));
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            try {
                $res = $this->router->dispatch(new Request([], ['_token' => $this->token], ['crm_session' => $this->sid], [], [
                    'REQUEST_METHOD' => $method, 'REQUEST_URI' => '/admin/audit', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
                ], ''));
                self::assertContains($res->getStatus(), [404, 405], $method);
            } catch (\App\Exceptions\HttpException $e) {
                self::assertContains($e->getStatusCode(), [404, 405], $method);
            }
        }
    }
}
