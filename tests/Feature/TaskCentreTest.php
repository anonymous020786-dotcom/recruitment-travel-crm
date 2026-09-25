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
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Services\TaskService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** The task centre: who sees which tasks, the tabs, creating / completing / cancelling / reassigning, and the screen. */
final class TaskCentreTest extends DbTestCase
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
        $this->branchA = $this->branch('TKX-A');
        $this->branchB = $this->branch('TKX-B');
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM tasks WHERE title LIKE 'TKT %'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'tasks' AND action LIKE 'task\\_%'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['TKX-%']);
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2))]);
    }

    private function user(string $role, ?int $branch = null, string $name = 'TK person'): int
    {
        $branch ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => $name, 'email' => 'tk_' . bin2hex(random_bytes(4)) . '@dev.local',
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

    private function service(): TaskService
    {
        return $this->app->get(TaskService::class);
    }

    /** @param array<string,mixed> $over */
    private function task(int $assignee, array $over = []): string
    {
        $publicId = Ulid::generate();
        $this->db->insertRow('tasks', $over + [
            'public_id' => $publicId, 'title' => 'TKT task', 'related_type' => 'none', 'branch_id' => $this->branchA, 'assigned_to' => $assignee,
            'priority' => 'medium', 'status' => 'pending', 'source' => 'manual', 'created_by' => $assignee,
        ]);

        return $publicId;
    }

    /** @return list<string> titles the viewer's open+all list shows */
    private function titles(User $viewer, string $tab = 'all', array $more = []): array
    {
        $rows = $this->service()->page($viewer, $this->service()->filters(['tab' => $tab] + $more), 1, 100)['rows'];
        $titles = array_values(array_filter(array_column($rows, 'title'), static fn (string $t): bool => str_starts_with($t, 'TKT ')));
        sort($titles);

        return $titles;
    }

    private function stateOf(string $publicId): string
    {
        return (string) $this->db->selectValue('SELECT status FROM tasks WHERE public_id = ?', [$publicId]);
    }

    private function assignedTo(string $publicId): int
    {
        return (int) $this->db->selectValue('SELECT assigned_to FROM tasks WHERE public_id = ?', [$publicId]);
    }

    // ---- filters and visibility ----------------------------------------------------------------------------------

    public function test_filters_are_normalised_and_unknown_values_fall_back(): void
    {
        $f = $this->service()->filters(['tab' => 'nonsense', 'priority' => 'critical', 'related' => 'x', 'assignee' => '-5', 'q' => str_repeat('q', 200)]);
        self::assertSame(['open', '', '', 0, 80], [$f['tab'], $f['priority'], $f['related'], $f['assignee'], strlen($f['q'])]);
        self::assertSame(['overdue', 'high', 'invoice', 7], array_values(array_intersect_key($this->service()->filters(['tab' => 'overdue', 'priority' => 'high', 'related' => 'invoice', 'assignee' => '7']), array_flip(['tab', 'priority', 'related', 'assignee']))));
    }

    public function test_people_see_their_own_tasks_and_those_they_created_unless_they_may_see_everything(): void
    {
        $counselor = $this->user('counselor');
        $colleague = $this->user('counselor');
        $manager = $this->user('manager');
        $mine = $this->task($counselor, ['title' => 'TKT mine']);
        $this->task($colleague, ['title' => 'TKT colleague']);
        $this->task($colleague, ['title' => 'TKT set by me for colleague', 'created_by' => $counselor]);
        $this->task($this->user('manager', $this->branchB), ['title' => 'TKT other branch', 'branch_id' => $this->branchB]);

        self::assertSame(['TKT mine', 'TKT set by me for colleague'], $this->titles($this->model($counselor)));
        self::assertSame(['TKT colleague', 'TKT mine', 'TKT set by me for colleague'], $this->titles($this->model($manager)), 'a manager sees the whole branch, not other branches');
        self::assertSame(['TKT colleague', 'TKT mine', 'TKT other branch', 'TKT set by me for colleague'], $this->titles($this->model($this->user('admin', $this->branchB))), 'admins see every branch');
        self::assertNotEmpty($mine);

        self::assertSame(['TKT colleague', 'TKT set by me for colleague'], $this->titles($this->model($manager), 'all', ['assignee' => $colleague]), 'a viewer who sees everyone can filter by person');
        self::assertSame($this->titles($this->model($counselor)), $this->titles($this->model($counselor), 'all', ['assignee' => $colleague]), 'the person filter means nothing to a viewer who only sees their own');
    }

    public function test_the_tabs_split_tasks_by_state_and_due_date(): void
    {
        $u = $this->user('manager');
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
        $today = gmdate('Y-m-d');
        $later = gmdate('Y-m-d', strtotime('+5 days'));
        $this->task($u, ['title' => 'TKT overdue', 'due_date' => $yesterday]);
        $this->task($u, ['title' => 'TKT today', 'due_date' => $today]);
        $this->task($u, ['title' => 'TKT later', 'due_date' => $later]);
        $this->task($u, ['title' => 'TKT nodate']);
        $this->task($u, ['title' => 'TKT done', 'status' => 'completed', 'completed_at' => gmdate('Y-m-d H:i:s'), 'due_date' => $yesterday]);
        $this->task($u, ['title' => 'TKT cancelled', 'status' => 'cancelled']);
        $viewer = $this->model($u);

        self::assertSame(['TKT later', 'TKT nodate', 'TKT overdue', 'TKT today'], $this->titles($viewer, 'open'));
        self::assertSame(['TKT overdue'], $this->titles($viewer, 'overdue'), 'a completed task is never overdue');
        self::assertSame(['TKT today'], $this->titles($viewer, 'today'));
        self::assertSame(['TKT done'], $this->titles($viewer, 'done'));
        self::assertSame(['TKT cancelled'], $this->titles($viewer, 'cancelled'));
        self::assertCount(6, $this->titles($viewer, 'all'));

        $counts = $this->service()->page($viewer, $this->service()->filters([]), 1)['counts'];
        self::assertGreaterThanOrEqual(4, $counts['open']);
        self::assertGreaterThanOrEqual(1, $counts['overdue']);
        self::assertGreaterThanOrEqual(6, $counts['all']);
    }

    public function test_open_tasks_are_ordered_by_due_date_then_priority_with_undated_last(): void
    {
        $u = $this->user('manager');
        $d = gmdate('Y-m-d', strtotime('+3 days'));
        $this->task($u, ['title' => 'TKT c undated', 'priority' => 'urgent']);
        $this->task($u, ['title' => 'TKT b low', 'due_date' => $d, 'priority' => 'low']);
        $this->task($u, ['title' => 'TKT a urgent', 'due_date' => $d, 'priority' => 'urgent']);
        $this->task($u, ['title' => 'TKT 0 sooner', 'due_date' => gmdate('Y-m-d', strtotime('+1 day')), 'priority' => 'low']);

        $rows = $this->service()->page($this->model($u), $this->service()->filters(['q' => 'TKT']), 1, 100)['rows'];

        self::assertSame(['TKT 0 sooner', 'TKT a urgent', 'TKT b low', 'TKT c undated'], array_column($rows, 'title'));
    }

    public function test_a_tasks_record_link_points_at_the_right_screen(): void
    {
        $u = $this->user('manager');
        $emp = (int) $this->db->insertRow('employers', ['public_id' => $pid = Ulid::generate(), 'employer_number' => 'EMP-TKX-' . bin2hex(random_bytes(3)), 'company_name' => 'TKT Co', 'country' => 'AE', 'status' => 'active', 'branch_id' => $this->branchA, 'created_by' => $u]);
        $this->task($u, ['title' => 'TKT about employer', 'related_type' => 'employer', 'related_id' => $emp]);
        $this->task($u, ['title' => 'TKT about flight', 'related_type' => 'travel', 'related_id' => 1]);
        $this->task($u, ['title' => 'TKT gone', 'related_type' => 'invoice', 'related_id' => 999999999]);

        $result = $this->service()->page($this->model($u), $this->service()->filters(['tab' => 'all', 'q' => 'TKT']), 1);
        $byTitle = [];
        foreach ($result['rows'] as $r) {
            $byTitle[$r['title']] = $result['links'][(int) $r['id']] ?? null;
        }

        self::assertSame('/employers/' . $pid, $byTitle['TKT about employer']);
        self::assertSame('/travel', $byTitle['TKT about flight']);
        self::assertNull($byTitle['TKT gone'], 'a record that no longer exists gives no link, not an error');
        $this->db->affectingStatement('DELETE FROM employers WHERE id = ?', [$emp]);
    }

    // ---- creating ------------------------------------------------------------------------------------------------

    public function test_creating_a_standalone_task_audits_it_and_tells_the_assignee(): void
    {
        $manager = $this->model($this->user('manager', $this->branchA, 'TK Boss'));
        $assignee = $this->user('counselor', $this->branchA, 'TK Worker');

        $id = $this->service()->create(['title' => 'TKT call the client', 'description' => 'about the visa', 'priority' => 'high', 'due_date' => gmdate('Y-m-d', strtotime('+2 days')), 'due_time' => '14:30', 'assigned_to' => (string) $assignee], $manager);

        $row = $this->db->selectOne('SELECT * FROM tasks WHERE public_id = ?', [$id]);
        self::assertSame($assignee, (int) $row['assigned_to']);
        self::assertSame($this->branchA, (int) $row['branch_id']);
        self::assertSame(['none', 'manual', 'high', '14:30:00'], [$row['related_type'], $row['source'], $row['priority'], $row['due_time']]);
        self::assertSame($manager->id, (int) $row['created_by']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'task_created' AND record_id = ?", [$row['id']]));
        $note = $this->db->selectOne("SELECT * FROM notifications WHERE user_id = ? AND type = 'task_assigned'", [$assignee]);
        self::assertNotNull($note);
        self::assertStringContainsString('TKT call the client', (string) $note['title']);
        self::assertStringContainsString('TK Boss', (string) $note['body']);

        // giving yourself a task does not notify you
        $me = $this->service()->create(['title' => 'TKT note to self', 'assigned_to' => (string) $manager->id], $manager);
        self::assertNotSame('', $me);
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'task_assigned'", [$manager->id]));
    }

    public function test_creating_is_validated_and_limited_to_people_in_your_branches(): void
    {
        $manager = $this->model($this->user('manager', $this->branchA));
        $elsewhere = $this->user('counselor', $this->branchB);
        $inactive = $this->user('counselor', $this->branchA);
        $this->db->affectingStatement('UPDATE users SET is_active = 0 WHERE id = ?', [$inactive]);

        foreach ([
            'no title' => ['title' => ' ', 'assigned_to' => (string) $manager->id],
            'past date' => ['title' => 'TKT x', 'due_date' => '2020-01-01', 'assigned_to' => (string) $manager->id],
            'bad time' => ['title' => 'TKT x', 'due_time' => '25:99', 'assigned_to' => (string) $manager->id],
            'other branch' => ['title' => 'TKT x', 'assigned_to' => (string) $elsewhere],
            'deactivated' => ['title' => 'TKT x', 'assigned_to' => (string) $inactive],
            'nobody' => ['title' => 'TKT x', 'assigned_to' => '999999999'],
        ] as $label => $bad) {
            try {
                $this->service()->create($bad, $manager);
                self::fail("accepted {$label}");
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors(), $label);
            }
        }
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM tasks WHERE title = 'TKT x'"));
    }

    // ---- completing, cancelling, reassigning ----------------------------------------------------------------------

    public function test_completing_and_cancelling_follow_visibility_and_state(): void
    {
        $counselor = $this->user('counselor');
        $colleague = $this->user('counselor');
        $mine = $this->task($counselor, ['title' => 'TKT mine']);
        $theirs = $this->task($colleague, ['title' => 'TKT theirs']);
        $c = $this->model($counselor);

        $this->service()->complete($mine, $c);
        self::assertSame('completed', $this->stateOf($mine));
        self::assertNotNull($this->db->selectValue('SELECT completed_at FROM tasks WHERE public_id = ?', [$mine]));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'task_completed'"));

        try {
            $this->service()->complete($mine, $c);
            self::fail('completed a closed task');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('already closed', $e->getMessage());
        }
        try {
            $this->service()->complete($theirs, $c);
            self::fail('a counselor completed a colleague\'s task');
        } catch (DomainRuleException $e) {
            self::assertSame(404, $e->httpStatus(), 'a hidden task looks like a missing one');
        }
        self::assertSame('pending', $this->stateOf($theirs));

        $this->service()->cancel($theirs, $this->model($this->user('manager')));   // a manager sees the whole branch
        self::assertSame('cancelled', $this->stateOf($theirs));
    }

    public function test_only_people_with_tasks_assign_can_reassign_and_the_task_moves_with_its_new_owner(): void
    {
        $manager = $this->model($this->user('manager', $this->branchA));
        $counselor = $this->user('counselor', $this->branchA, 'TK New Owner');
        $otherBranch = $this->user('counselor', $this->branchB);
        $task = $this->task($manager->id, ['title' => 'TKT hand over']);

        try {
            $this->service()->reassign($task, $counselor, $this->model($counselor));
            self::fail('a counselor reassigned');
        } catch (AuthorizationException) {
            self::assertSame($manager->id, $this->assignedTo($task));
        }
        try {
            $this->service()->reassign($task, $otherBranch, $manager);
            self::fail('assigned outside the branches');
        } catch (ValidationException) {
            self::assertSame($manager->id, $this->assignedTo($task));
        }

        $this->service()->reassign($task, $counselor, $manager);

        self::assertSame($counselor, $this->assignedTo($task));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'task_assigned'", [$counselor]));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'task_reassigned'"));
        $this->service()->reassign($task, $counselor, $manager);   // same person again: nothing happens
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'task_assigned'", [$counselor]));
    }

    // ---- the screens ---------------------------------------------------------------------------------------------------

    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => time()] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    /** @param array<string,mixed> $post @param array<string,string> $query */
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
        } catch (AuthorizationException) {
            return 403;
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_the_sidebars_tasks_link_now_lands_on_a_real_page_for_the_right_people(): void
    {
        $this->actAs(null);
        self::assertSame(302, $this->code('GET', '/tasks'));

        $this->actAs($this->user('counselor'));
        self::assertSame(200, $this->code('GET', '/tasks'));
        self::assertSame(200, $this->code('GET', '/tasks/create'));
        self::assertSame(403, $this->code('POST', '/tasks/' . Ulid::generate() . '/reassign', ['assigned_to' => '1']), 'a counselor cannot reassign');
    }

    public function test_listing_creating_completing_and_cancelling_through_the_screens(): void
    {
        $manager = $this->user('manager', $this->branchA, 'TK Manager');
        $worker = $this->user('counselor', $this->branchA, 'TK Worker');
        $this->task($worker, ['title' => 'TKT <b>bold</b> & risky', 'description' => '<script>alert(1)</script>', 'due_date' => gmdate('Y-m-d', strtotime('-2 days'))]);
        $this->actAs($manager);

        $page = $this->send('GET', '/tasks', [], ['q' => 'TKT'])->getBody();
        self::assertStringContainsString('TKT &lt;b&gt;bold&lt;/b&gt; &amp; risky', $page);
        self::assertStringNotContainsString('<script>alert(1)', $page);
        self::assertStringContainsString('overdue', $page);
        self::assertStringContainsString('TK Worker', $page);

        $res = $this->send('POST', '/tasks', ['title' => 'TKT from the screen', 'priority' => 'urgent', 'assigned_to' => (string) $worker, 'due_date' => '', 'due_time' => '', 'description' => '']);
        self::assertSame(302, $res->getStatus());
        $id = (string) $this->db->selectValue("SELECT public_id FROM tasks WHERE title = 'TKT from the screen'");
        self::assertNotSame('', $id);

        $bad = $this->send('POST', '/tasks', ['title' => '', 'assigned_to' => (string) $worker]);
        self::assertSame('/tasks/create', $bad->getHeader('Location'));
        self::assertStringContainsString('required', strtolower($this->send('GET', '/tasks/create')->getBody()));

        $done = $this->send('POST', "/tasks/{$id}/complete", ['back' => '/tasks?tab=open&priority=urgent']);
        self::assertSame('/tasks?tab=open&priority=urgent', $done->getHeader('Location'));
        self::assertSame('completed', $this->stateOf($id));

        $other = (string) $this->task($worker, ['title' => 'TKT to cancel']);
        $evil = $this->send('POST', "/tasks/{$other}/cancel", ['back' => 'https://evil.example/tasks']);
        self::assertSame('/tasks', $evil->getHeader('Location'), 'the return address can only ever be a /tasks address');
        self::assertSame('cancelled', $this->stateOf($other));

        $again = $this->send('POST', "/tasks/{$other}/complete", ['x' => '1']);
        self::assertSame(302, $again->getStatus(), 'a closed task gives a message, not an error page');
        self::assertSame('cancelled', $this->stateOf($other));
    }

    public function test_the_task_repository_scopes_public_id_lookups_to_the_viewers_branches(): void
    {
        $inB = $this->user('manager', $this->branchB);
        $task = $this->task($inB, ['branch_id' => $this->branchB, 'title' => 'TKT b only']);
        $scopeA = $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($this->model($this->user('manager', $this->branchA)));
        $scopeB = $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($this->model($inB));
        $repo = $this->app->get(TaskRepository::class);

        self::assertNull($repo->findByPublicId($task, $scopeA));
        self::assertNotNull($repo->findByPublicId($task, $scopeB));
    }
    public function test_the_dashboard_shows_the_viewers_own_overdue_and_due_today_counts(): void
    {
        $me = $this->user('counselor', $this->branchA, 'TK Me');
        $other = $this->user('counselor');
        $this->task($me, ['title' => 'TKT o1', 'due_date' => gmdate('Y-m-d', strtotime('-3 days'))]);
        $this->task($me, ['title' => 'TKT o2', 'due_date' => gmdate('Y-m-d', strtotime('-1 day'))]);
        $this->task($me, ['title' => 'TKT t1', 'due_date' => gmdate('Y-m-d')]);
        $this->task($me, ['title' => 'TKT later', 'due_date' => gmdate('Y-m-d', strtotime('+4 days'))]);
        $this->task($me, ['title' => 'TKT closed', 'due_date' => gmdate('Y-m-d', strtotime('-5 days')), 'status' => 'completed']);
        $this->task($other, ['title' => 'TKT theirs', 'due_date' => gmdate('Y-m-d', strtotime('-9 days'))]);

        self::assertSame(['open' => 4, 'overdue' => 2, 'today' => 1], $this->app->get(TaskRepository::class)->countsForAssignee($me));

        $this->actAs($me);
        $page = $this->send('GET', '/dashboard')->getBody();
        self::assertStringContainsString('My tasks overdue', $page);
        self::assertStringContainsString('href="/tasks?tab=overdue"', $page);
        self::assertStringContainsString('href="/tasks?tab=today"', $page);
    }
}
