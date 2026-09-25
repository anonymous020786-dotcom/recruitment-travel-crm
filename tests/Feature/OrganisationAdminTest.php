<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\LeadRepository;
use App\Repositories\UserRepository;
use App\Services\BranchAdminService;
use App\Services\LeadSourceAdminService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Branches and Admin → Lead sources: the rules that keep the organisation's lists usable, and the screens. */
final class OrganisationAdminTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private string $token = '';
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> real branches we switched off for a test */
    private array $pausedBranches = [];
    /** @var list<array<string,mixed>> the real lead sources (state), put back afterwards */
    private array $sourceSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->sourceSnapshot = $this->db->select('SELECT id, is_active, sort_order FROM lead_sources');
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        foreach ($this->pausedBranches as $id) {
            $this->db->affectingStatement('UPDATE branches SET is_active = 1 WHERE id = ?', [$id]);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM lead_sources WHERE name LIKE 'OAX %'");
        foreach ($this->sourceSnapshot as $s) {
            $this->db->affectingStatement('UPDATE lead_sources SET is_active = ?, sort_order = ? WHERE id = ?', [$s['is_active'], $s['sort_order'], $s['id']]);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE (module = 'branches' AND action LIKE 'branch\\_%') OR (module = 'settings' AND action LIKE 'lead\\_source\\_%')");
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'OAX-%'");
    }

    private function user(string $role, ?int $branch = null, array $over = []): int
    {
        $id = (int) $this->db->insertRow('users', $over + [
            'public_id' => Ulid::generate(), 'name' => "OA {$role}", 'email' => 'oa_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branch, 'is_active' => 1,
        ]);
        if ($branch !== null) {
            $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branch]);
        }
        $this->userIds[] = $id;

        return $id;
    }

    private function model(int $id): User
    {
        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function branchSvc(): BranchAdminService
    {
        return $this->app->get(BranchAdminService::class);
    }

    private function sourceSvc(): LeadSourceAdminService
    {
        return $this->app->get(LeadSourceAdminService::class);
    }

    /** @param array<string,mixed> $over */
    private function branchInput(array $over = []): array
    {
        return $over + ['name' => 'OAX Pune Office', 'code' => 'oax-pne', 'city' => 'Pune', 'state' => 'MH', 'country' => 'in', 'phone' => '+91 20 5555 0101', 'email' => 'Pune@Example.TEST'];
    }

    /** @return array<string,mixed> */
    private function branchRow(string $publicId): array
    {
        return $this->db->selectOne('SELECT * FROM branches WHERE public_id = ?', [$publicId]) ?? self::fail('no such branch');
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

    /** Switch every real active branch off (restored in tearDown) so the ones a test creates are the only ones. */
    private function isolateBranches(): void
    {
        foreach ($this->db->select("SELECT id FROM branches WHERE is_active = 1 AND code NOT LIKE 'OAX-%'") as $r) {
            $this->pausedBranches[] = (int) $r['id'];
            $this->db->affectingStatement('UPDATE branches SET is_active = 0 WHERE id = ?', [$r['id']]);
        }
    }

    // ---- branches: writing ---------------------------------------------------------------------------------------

    public function test_a_branch_is_created_normalised_and_audited(): void
    {
        $admin = $this->model($this->user('admin'));

        $id = $this->branchSvc()->create($this->branchInput(), $admin);

        $row = $this->branchRow($id);
        self::assertSame(['OAX-PNE', 'IN', 'pune@example.test', 1], [$row['code'], $row['country'], $row['email'], (int) $row['is_active']]);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'branch_created' AND record_id = ?", [$row['id']]));
    }

    public function test_branch_input_is_validated_and_names_and_codes_are_unique(): void
    {
        $admin = $this->model($this->user('admin'));
        $this->branchSvc()->create($this->branchInput(), $admin);

        foreach ([
            'no name' => ['name' => ' '], 'long name' => ['name' => str_repeat('n', 121)], 'duplicate name' => ['name' => 'oax pune office', 'code' => 'OAX-2'],
            'no code' => ['code' => ''], 'bad code' => ['code' => 'a b'], 'double hyphen' => ['code' => 'OAX--1'], 'long code' => ['code' => 'OAX-' . str_repeat('9', 20)],
            'duplicate code' => ['name' => 'OAX Other', 'code' => 'oax-pne'], 'country' => ['name' => 'OAX C', 'code' => 'OAX-C', 'country' => 'IND'],
            'phone' => ['name' => 'OAX P', 'code' => 'OAX-P', 'phone' => 'call'], 'email' => ['name' => 'OAX E', 'code' => 'OAX-E', 'email' => 'nope'],
            'multiline city' => ['name' => 'OAX M', 'code' => 'OAX-M', 'city' => "a\nb"],
        ] as $label => $bad) {
            try {
                $this->branchSvc()->create($this->branchInput($bad), $admin);
                self::fail("accepted {$label}");
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors(), $label);
            }
        }
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM branches WHERE code LIKE 'OAX-%'"));
    }

    public function test_editing_changes_only_what_differs_and_a_no_op_writes_nothing(): void
    {
        $admin = $this->model($this->user('admin'));
        $id = $this->branchSvc()->create($this->branchInput(), $admin);

        $this->branchSvc()->update($id, $this->branchInput(), $admin);   // same values, own code is not a duplicate of itself
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'branch_updated'"));

        $this->branchSvc()->update($id, $this->branchInput(['city' => 'Pimpri', 'phone' => '']), $admin);
        $row = $this->branchRow($id);
        self::assertSame(['Pimpri', null], [$row['city'], $row['phone']]);
        $log = (string) $this->db->selectValue("SELECT new_values FROM activity_logs WHERE action = 'branch_updated' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('Pimpri', $log);
        self::assertStringNotContainsString('Pune', $log, 'only the changed fields are logged');
    }

    public function test_only_people_with_branches_manage_can_write(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->branchSvc()->create($this->branchInput(), $this->model($this->user('manager')));
    }

    // ---- branches: switching off ------------------------------------------------------------------------------------

    public function test_the_last_active_branch_cannot_be_switched_off(): void
    {
        $this->isolateBranches();
        $admin = $this->model($this->user('admin'));
        $only = $this->branchSvc()->create($this->branchInput(), $admin);

        $this->refused(fn () => $this->branchSvc()->deactivate($only, $admin), 'BRANCH_LAST');
        self::assertSame(1, (int) $this->branchRow($only)['is_active']);
    }

    public function test_a_branch_that_would_strand_active_people_cannot_be_switched_off_until_they_move(): void
    {
        $this->isolateBranches();
        $admin = $this->model($this->user('admin'));
        $a = $this->branchSvc()->create($this->branchInput(), $admin);
        $b = $this->branchSvc()->create($this->branchInput(['name' => 'OAX Second', 'code' => 'OAX-2']), $admin);
        $idA = (int) $this->branchRow($a)['id'];
        $idB = (int) $this->branchRow($b)['id'];

        $stranded = $this->user('counselor', $idA);                                            // primary A only → blocked
        $twoBranches = $this->user('counselor', $idA);
        $this->db->insertRow('user_branches', ['user_id' => $twoBranches, 'branch_id' => $idB]); // A and B → not stranded
        $orgWide = $this->user('manager', $idA, ['is_org_wide' => 1]);                          // organisation-wide → never blocks
        $gone = $this->user('counselor', $idA, ['is_active' => 0]);                             // already inactive → never blocks
        self::assertNotEmpty([$orgWide, $gone]);

        $this->refused(fn () => $this->branchSvc()->deactivate($a, $admin), 'BRANCH_HAS_PEOPLE');
        self::assertSame(1, (int) $this->branchRow($a)['is_active']);

        // move the stranded person (and the two-branch person's primary) to B
        foreach ([$stranded, $twoBranches] as $uid) {
            $this->db->affectingStatement('UPDATE users SET primary_branch_id = ? WHERE id = ?', [$idB, $uid]);
        }
        $this->db->affectingStatement('DELETE FROM user_branches WHERE user_id = ? AND branch_id = ?', [$stranded, $idA]);
        $this->db->insertRow('user_branches', ['user_id' => $stranded, 'branch_id' => $idB]);
        $this->db->affectingStatement('UPDATE users SET primary_branch_id = ? WHERE id = ?', [$idB, $orgWide]);

        $this->branchSvc()->deactivate($a, $admin);
        self::assertSame(0, (int) $this->branchRow($a)['is_active']);
        $this->branchSvc()->reactivate($a, $admin);
        self::assertSame(1, (int) $this->branchRow($a)['is_active']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'branch_deactivated' AND record_id = ?", [$idA]));
    }

    public function test_a_switched_off_branch_disappears_from_the_user_form_choices_but_keeps_its_records(): void
    {
        $admin = $this->model($this->user('admin'));
        $extra = $this->branchSvc()->create($this->branchInput(['name' => 'OAX Extra', 'code' => 'OAX-X']), $admin);
        $this->branchSvc()->deactivate($extra, $admin);

        $names = array_column($this->app->get(\App\Repositories\UserAdminRepository::class)->branches(), 'name');

        self::assertNotContains('OAX Extra', $names);
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM branches WHERE public_id = ?', [$extra]), 'the row is kept');
    }

    // ---- lead sources -------------------------------------------------------------------------------------------------

    public function test_a_source_is_added_at_the_end_with_a_clean_unique_name(): void
    {
        $admin = $this->model($this->user('admin'));

        $id = $this->sourceSvc()->create('  OAX   Radio   ad ', $admin);
        $row = $this->db->selectOne('SELECT * FROM lead_sources WHERE id = ?', [$id]);

        self::assertSame('OAX Radio ad', $row['name']);
        self::assertSame((int) $this->db->selectValue('SELECT MAX(sort_order) FROM lead_sources'), (int) $row['sort_order']);
        foreach (['', str_repeat('s', 81), "OAX bell\x07", 'oax radio ad'] as $bad) {
            try {
                $this->sourceSvc()->create($bad, $admin);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException $e) {
                self::assertArrayHasKey('name', $e->errors());
            }
        }
    }

    public function test_website_cannot_be_renamed_or_switched_off_and_the_last_active_source_stays(): void
    {
        $admin = $this->model($this->user('admin'));
        $website = (int) $this->db->selectValue("SELECT id FROM lead_sources WHERE name = 'Website'");
        if ($website === 0) {
            $website = $this->sourceSvc()->create('Website', $admin);   // (only if the reference data lacks it)
        }
        $mine = $this->sourceSvc()->create('OAX Mine', $admin);

        $this->refused(fn () => $this->sourceSvc()->rename($website, 'Our site', $admin), 'LEAD_SOURCE_PROTECTED');
        $this->refused(fn () => $this->sourceSvc()->setActive($website, false, $admin), 'LEAD_SOURCE_PROTECTED');

        foreach ($this->db->select('SELECT id FROM lead_sources WHERE id NOT IN (?, ?)', [$website, $mine]) as $r) {
            $this->db->affectingStatement('UPDATE lead_sources SET is_active = 0 WHERE id = ?', [$r['id']]);
        }
        $this->sourceSvc()->setActive($mine, false, $admin);   // Website is still active, so this is allowed
        $this->refused(fn () => $this->sourceSvc()->setActive($website, false, $admin), 'LEAD_SOURCE_PROTECTED');
        $this->sourceSvc()->setActive($mine, true, $admin);
        $this->db->affectingStatement('UPDATE lead_sources SET is_active = 0 WHERE id = ?', [$website]);   // (force a state where Mine is the only one on)
        $this->refused(fn () => $this->sourceSvc()->setActive($mine, false, $admin), 'LEAD_SOURCE_LAST');
    }

    public function test_renaming_and_switching_off_change_what_the_lead_form_offers(): void
    {
        $admin = $this->model($this->user('admin'));
        $id = $this->sourceSvc()->create('OAX Flyer', $admin);
        $offered = static fn (LeadRepository $r): array => array_column($r->sourceOptions(), 'name');
        $repo = $this->app->get(LeadRepository::class);
        self::assertContains('OAX Flyer', $offered($repo));

        $this->sourceSvc()->rename($id, 'OAX Flyer 2', $admin);
        self::assertContains('OAX Flyer 2', $offered($repo));

        $this->sourceSvc()->setActive($id, false, $admin);
        self::assertNotContains('OAX Flyer 2', $offered($repo), 'a switched-off source is no longer offered on new leads');
        self::assertSame('OAX Flyer 2', $this->db->selectValue('SELECT name FROM lead_sources WHERE id = ?', [$id]), 'but the row (and old leads) keep it');
        self::assertSame(3, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE module = 'settings' AND action LIKE 'lead\\_source\\_%' AND record_id = ?", [$id]));
    }

    public function test_moving_a_source_swaps_it_with_its_neighbour_and_tidies_the_numbering(): void
    {
        $admin = $this->model($this->user('admin'));
        $this->db->affectingStatement('UPDATE lead_sources SET sort_order = 0');   // the seeded list may have every number the same
        $a = $this->sourceSvc()->create('OAX A', $admin);
        $b = $this->sourceSvc()->create('OAX B', $admin);
        $order = fn (): array => array_values(array_filter(array_column($this->app->get(\App\Repositories\LeadSourceAdminRepository::class)->all(), 'name'), static fn (string $n): bool => str_starts_with($n, 'OAX ')));

        self::assertSame(['OAX A', 'OAX B'], $order());
        $this->sourceSvc()->move($b, 'up', $admin);
        self::assertSame(['OAX B', 'OAX A'], $order());
        $this->sourceSvc()->move($b, 'up', $admin);                                  // now first among ours, but not first overall
        self::assertLessThan((int) $this->db->selectValue('SELECT sort_order FROM lead_sources WHERE id = ?', [$a]), (int) $this->db->selectValue('SELECT sort_order FROM lead_sources WHERE id = ?', [$b]));
        $numbers = array_column($this->db->select('SELECT sort_order FROM lead_sources ORDER BY sort_order'), 'sort_order');
        self::assertSame(range(1, count($numbers)), array_map('intval', $numbers), 'the numbering is 1..n with no gaps or repeats');

        $first = (int) $this->db->selectValue('SELECT id FROM lead_sources ORDER BY sort_order LIMIT 1');
        $this->sourceSvc()->move($first, 'up', $admin);                              // already at the top: nothing happens
        self::assertSame($first, (int) $this->db->selectValue('SELECT id FROM lead_sources ORDER BY sort_order LIMIT 1'));
    }

    public function test_only_people_with_settings_manage_can_change_sources(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->sourceSvc()->create('OAX Nope', $this->model($this->user('manager')));
    }

    // ---- the screens ----------------------------------------------------------------------------------------------------

    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => time()] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
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

    private function code(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->send($method, $uri, $post)->getStatus();
        } catch (AuthorizationException) {
            return 403;
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_who_reaches_which_admin_screen(): void
    {
        $this->actAs(null);
        self::assertSame(302, $this->code('GET', '/admin/branches'));

        $this->actAs($this->user('manager'));   // has settings.view but neither branches.* nor settings.manage
        self::assertSame(403, $this->code('GET', '/admin/branches'));
        self::assertSame(403, $this->code('GET', '/admin/branches/create'));
        self::assertSame(200, $this->code('GET', '/admin/lead-sources'));
        self::assertSame(403, $this->code('POST', '/admin/lead-sources', ['name' => 'OAX Sneaky']));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM lead_sources WHERE name = 'OAX Sneaky'"));

        $this->actAs($this->user('admin'));
        self::assertSame(200, $this->code('GET', '/admin/branches'));
        self::assertSame(200, $this->code('GET', '/admin/branches/create'));
        self::assertSame(200, $this->code('GET', '/admin/lead-sources'));
        self::assertSame(404, $this->code('GET', '/admin/branches/no-such-branch/edit'));
    }

    public function test_managing_branches_and_sources_through_the_screens(): void
    {
        $this->actAs($this->user('admin'));

        $res = $this->send('POST', '/admin/branches', $this->branchInput(['name' => 'OAX <b>Screen</b>', 'code' => 'oax-scr']));
        self::assertSame('/admin/branches', $res->getHeader('Location'));
        $pid = (string) $this->db->selectValue("SELECT public_id FROM branches WHERE code = 'OAX-SCR'");
        self::assertNotSame('', $pid);
        self::assertStringContainsString('OAX &lt;b&gt;Screen&lt;/b&gt;', $this->send('GET', '/admin/branches')->getBody());
        self::assertStringContainsString('value="OAX-SCR"', $this->send('GET', "/admin/branches/{$pid}/edit")->getBody());

        $bad = $this->send('POST', '/admin/branches', $this->branchInput(['name' => 'OAX Bad', 'code' => 'x y']));
        self::assertSame('/admin/branches/create', $bad->getHeader('Location'));
        self::assertStringContainsString('short code', $this->send('GET', '/admin/branches/create')->getBody());

        $this->send('PUT', "/admin/branches/{$pid}", $this->branchInput(['name' => 'OAX Renamed', 'code' => 'oax-scr', '_method' => 'PUT']));
        self::assertSame('OAX Renamed', $this->branchRow($pid)['name']);
        $this->send('POST', "/admin/branches/{$pid}/deactivate", ['x' => '1']);
        self::assertSame(0, (int) $this->branchRow($pid)['is_active']);
        $this->send('POST', "/admin/branches/{$pid}/reactivate", ['x' => '1']);
        self::assertSame(1, (int) $this->branchRow($pid)['is_active']);

        $this->send('POST', '/admin/lead-sources', ['name' => 'OAX Screen Source']);
        $sid = (int) $this->db->selectValue("SELECT id FROM lead_sources WHERE name = 'OAX Screen Source'");
        self::assertGreaterThan(0, $sid);
        self::assertStringContainsString('OAX Screen Source', $this->send('GET', '/admin/lead-sources')->getBody());
        $this->send('PUT', "/admin/lead-sources/{$sid}", ['_method' => 'PUT', 'name' => 'OAX Screen Source 2']);
        self::assertSame('OAX Screen Source 2', $this->db->selectValue('SELECT name FROM lead_sources WHERE id = ?', [$sid]));
        $this->send('POST', "/admin/lead-sources/{$sid}/toggle", ['active' => '0']);
        self::assertSame(0, (int) $this->db->selectValue('SELECT is_active FROM lead_sources WHERE id = ?', [$sid]));
        $this->send('POST', "/admin/lead-sources/{$sid}/move", ['direction' => 'up']);
        self::assertSame(302, $this->send('POST', "/admin/lead-sources/{$sid}/move", ['direction' => 'sideways'])->getStatus());
        self::assertSame(302, $this->send('POST', '/admin/lead-sources/999999999/toggle', ['active' => '1'])->getStatus(), 'an unknown source gives a message, not a crash');
    }
}
