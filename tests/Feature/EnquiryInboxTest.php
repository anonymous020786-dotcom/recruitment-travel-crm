<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\UserRepository;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** The website-enquiry inbox and the notifications screen, through the real router as signed-in users. */
final class EnquiryInboxTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private string $token = '';
    private int $branchId;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $enquiryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->branchId = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'EI branch', 'code' => 'EIX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('public_enquiries', 'leads')");
        $this->db->affectingStatement("DELETE FROM public_enquiries WHERE name LIKE 'EI %'");
        $this->db->affectingStatement("DELETE FROM lead_notes WHERE lead_id IN (SELECT id FROM leads WHERE branch_id = ?)", [$this->branchId]);
        $this->db->affectingStatement('DELETE FROM leads WHERE branch_id = ?', [$this->branchId]);
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement('DELETE FROM branches WHERE id = ?', [$this->branchId]);
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%'");
    }

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "EI {$role}", 'email' => 'ei_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branchId]);
        $this->userIds[] = $id;

        return $id;
    }

    /** @param int|null $userId null = a session with nobody signed in */
    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        // A fresh Auth AND Gate: the Gate holds the Auth it was built with, so switching user mid-test needs both.
        $auth = new \App\Auth\Auth($this->app, new UserRepository($this->db));
        $this->app->instance(\App\Auth\Auth::class, $auth);
        $gate = new \App\Auth\Gate($this->app, $this->app->get(\App\Auth\PermissionService::class), $auth);
        foreach ([\App\Models\Lead::class => \App\Policies\LeadPolicy::class] as $model => $policy) {
            $gate->policy($model, $policy);
        }
        $this->app->instance(\App\Auth\Gate::class, $gate);
    }

    private function send(string $method, string $uri, array $post = [], array $query = []): Response
    {
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }

        return $this->router->dispatch(new Request($query, $post, $this->sid !== '' ? ['crm_session' => $this->sid] : [], [], [
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

    /** @param array<string,mixed> $over */
    private function enquiry(string $name, array $over = []): int
    {
        $id = (int) $this->db->insertRow('public_enquiries', $over + ['type' => 'contact', 'name' => "EI {$name}", 'phone' => '98' . random_int(10000000, 99999999), 'message' => 'Hello there', 'status' => 'new', 'meta_json' => '{}']);
        $this->enquiryIds[] = $id;

        return $id;
    }

    // ---- access ----------------------------------------------------------------------------------

    public function test_the_inbox_needs_the_permission(): void
    {
        $id = $this->enquiry('Access');

        $this->actAs($this->user('accounts'));
        self::assertSame(403, $this->code('GET', '/enquiries'), 'accounts do not work the inbox');
        self::assertSame(403, $this->code('GET', "/enquiries/{$id}"));

        $this->actAs($this->user('manager'));
        self::assertSame(200, $this->code('GET', '/enquiries'));
        self::assertSame(200, $this->code('GET', "/enquiries/{$id}"));
        self::assertSame(404, $this->code('GET', '/enquiries/999999999'));
        self::assertSame(404, $this->code('GET', '/enquiries/abc'));

        $this->actAs(null);
        self::assertSame(302, $this->code('GET', '/enquiries'), 'anonymous users are sent to sign in');
    }

    public function test_the_list_defaults_to_new_and_filters_and_searches(): void
    {
        $this->enquiry('Fresh Person');
        $this->enquiry('Old Person', ['status' => 'reviewed']);
        $this->enquiry('Spammy', ['status' => 'spam']);
        $this->actAs($this->user('manager'));

        $default = $this->send('GET', '/enquiries')->getBody();
        self::assertStringContainsString('EI Fresh Person', $default);
        self::assertStringNotContainsString('EI Old Person', $default, 'the default view is the work queue');

        $any = $this->send('GET', '/enquiries', [], ['status' => '', 'q' => 'EI '])->getBody();
        self::assertStringContainsString('EI Old Person', $any);
        self::assertStringContainsString('EI Spammy', $any);

        $spam = $this->send('GET', '/enquiries', [], ['status' => 'spam', 'q' => 'EI '])->getBody();
        self::assertStringContainsString('EI Spammy', $spam);
        self::assertStringNotContainsString('EI Fresh Person', $spam);
    }

    public function test_message_and_name_are_escaped(): void
    {
        $id = $this->enquiry('Xss', ['message' => '<script>alert(1)</script>', 'name' => 'EI <b>Bold</b>']);
        $this->actAs($this->user('manager'));

        $html = $this->send('GET', "/enquiries/{$id}")->getBody();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<b>Bold</b>', $html);
    }

    // ---- triage ----------------------------------------------------------------------------------

    public function test_marking_reviewed_and_spam_is_guarded_and_audited(): void
    {
        $id = $this->enquiry('Triage');
        $this->actAs($this->user('manager'));

        self::assertSame(302, $this->code('POST', "/enquiries/{$id}/status", ['from' => 'new', 'to' => 'reviewed']));
        self::assertSame('reviewed', $this->db->selectValue('SELECT status FROM public_enquiries WHERE id = ?', [$id]));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module = 'public_enquiries' AND action = 'enquiry_status_changed' AND record_id = ?", [$id]));

        // a stale "from" (someone else already moved it) changes nothing
        $this->code('POST', "/enquiries/{$id}/status", ['from' => 'new', 'to' => 'spam']);
        self::assertSame('reviewed', $this->db->selectValue('SELECT status FROM public_enquiries WHERE id = ?', [$id]));

        // 'converted' can only be reached through convert()
        $this->code('POST', "/enquiries/{$id}/status", ['from' => 'reviewed', 'to' => 'converted']);
        self::assertSame('reviewed', $this->db->selectValue('SELECT status FROM public_enquiries WHERE id = ?', [$id]));
    }

    // ---- converting ---------------------------------------------------------------------------------

    public function test_converting_creates_a_website_lead_carrying_the_context(): void
    {
        $country = (string) $this->db->selectValue("SELECT code FROM countries WHERE code = 'AE'");
        $employer = (int) $this->db->insertRow('employers', ['public_id' => Ulid::generate(), 'employer_number' => 'EMP-EI-' . random_int(100000, 999999), 'company_name' => 'EI Co', 'country' => 'AE', 'branch_id' => $this->branchId]);
        $job = (int) $this->db->insertRow('jobs', ['public_id' => Ulid::generate(), 'job_number' => 'JOB-EI-' . random_int(100000, 999999), 'slug' => 'zz-ei-' . bin2hex(random_bytes(3)), 'title' => 'EI Electrician', 'employer_id' => $employer, 'country' => $country, 'status' => 'open', 'is_public' => 1]);
        $id = $this->enquiry('Applicant', ['type' => 'job_apply', 'job_id' => $job, 'message' => 'Ready to join next month', 'email' => 'ei-applicant@example.com']);
        $this->actAs($this->user('manager'));

        try {
            self::assertSame(302, $this->code('POST', "/enquiries/{$id}/convert", ['branch_id' => (string) $this->branchId]));

            $e = $this->db->selectOne('SELECT status, lead_id FROM public_enquiries WHERE id = ?', [$id]);
            self::assertSame('converted', $e['status']);
            $lead = $this->db->selectOne('SELECT l.*, s.name AS source FROM leads l LEFT JOIN lead_sources s ON s.id = l.source_id WHERE l.id = ?', [$e['lead_id']]);
            self::assertSame('EI Applicant', $lead['name']);
            self::assertSame($this->branchId, (int) $lead['branch_id']);
            self::assertSame('Website', $lead['source']);
            self::assertSame('AE', $lead['interested_country']);
            self::assertSame('EI Electrician', $lead['interested_job']);
            self::assertStringContainsString('Applied for job: EI Electrician', (string) $lead['notes']);
            self::assertStringContainsString('Ready to join next month', (string) $lead['notes']);

            // a second attempt is refused, and shows the lead link on the page
            $this->code('POST', "/enquiries/{$id}/convert", ['branch_id' => (string) $this->branchId]);
            self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM leads WHERE branch_id = ?', [$this->branchId]));
            self::assertStringContainsString($lead['lead_number'], $this->send('GET', "/enquiries/{$id}")->getBody());
        } finally {
            $this->db->affectingStatement('DELETE FROM public_enquiries WHERE id = ?', [$id]);
            $this->db->affectingStatement('DELETE FROM jobs WHERE id = ?', [$job]);
            $this->db->affectingStatement('DELETE FROM employers WHERE id = ?', [$employer]);
        }
    }

    public function test_an_existing_lead_is_linked_instead_of_duplicated(): void
    {
        $phone = '97' . random_int(10000000, 99999999);
        $manager = $this->user('manager');
        $this->actAs($manager);
        $first = $this->enquiry('Repeat One', ['phone' => $phone]);
        $second = $this->enquiry('Repeat Two', ['phone' => $phone]);

        $this->code('POST', "/enquiries/{$first}/convert", ['branch_id' => (string) $this->branchId]);
        $this->code('POST', "/enquiries/{$second}/convert", ['branch_id' => (string) $this->branchId]);

        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM leads WHERE branch_id = ?', [$this->branchId]), 'no duplicate lead');
        $a = $this->db->selectOne('SELECT status, lead_id FROM public_enquiries WHERE id = ?', [$first]);
        $b = $this->db->selectOne('SELECT status, lead_id FROM public_enquiries WHERE id = ?', [$second]);
        self::assertSame('converted', $b['status']);
        self::assertSame($a['lead_id'], $b['lead_id'], 'the second enquiry points at the same lead');
    }

    public function test_a_branch_outside_the_users_scope_is_refused(): void
    {
        $other = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'EI other', 'code' => 'EIY-' . bin2hex(random_bytes(2))]);
        $id = $this->enquiry('Scope');
        $this->actAs($this->user('manager'));

        try {
            $this->code('POST', "/enquiries/{$id}/convert", ['branch_id' => (string) $other]);
            self::assertSame('new', $this->db->selectValue('SELECT status FROM public_enquiries WHERE id = ?', [$id]));
            self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM leads WHERE branch_id = ?', [$other]));
        } finally {
            $this->db->affectingStatement('DELETE FROM branches WHERE id = ?', [$other]);
        }
    }

    // ---- notifications ---------------------------------------------------------------------------------

    private function notify(int $userId, array $over = []): int
    {
        return (int) $this->db->insertRow('notifications', $over + ['user_id' => $userId, 'type' => 'test', 'title' => 'EI note ' . bin2hex(random_bytes(2))]);
    }

    public function test_the_notifications_screen_lists_only_my_notifications(): void
    {
        $me = $this->user('manager');
        $other = $this->user('manager');
        $mine = $this->notify($me, ['title' => 'EI mine']);
        $this->notify($other, ['title' => 'EI theirs']);
        $this->actAs($me);

        $html = $this->send('GET', '/notifications')->getBody();

        self::assertStringContainsString('EI mine', $html);
        self::assertStringNotContainsString('EI theirs', $html);
        self::assertStringContainsString('1 unread', $html);
        self::assertStringContainsString("/notifications/{$mine}/open", $html);
    }

    public function test_opening_a_notification_marks_it_read_and_goes_to_the_record(): void
    {
        $me = $this->user('manager');
        $enq = $this->enquiry('Linked');
        $n = $this->notify($me, ['type' => 'enquiry_new', 'link_type' => 'enquiry', 'link_id' => $enq]);
        $this->actAs($me);

        $res = $this->send('GET', "/notifications/{$n}/open");

        self::assertSame(302, $res->getStatus());
        self::assertSame("/enquiries/{$enq}", $res->getHeader('Location'));
        self::assertNotNull($this->db->selectValue('SELECT read_at FROM notifications WHERE id = ?', [$n]));
    }

    public function test_a_notification_resolves_public_id_targets_and_alerts_without_a_link(): void
    {
        $repo = $this->app->get(\App\Repositories\NotificationRepository::class);
        $leadPid = Ulid::generate();
        $leadId = (int) $this->db->insertRow('leads', [
            'public_id' => $leadPid, 'lead_number' => 'LEAD-EI-' . random_int(100000, 999999), 'branch_id' => $this->branchId, 'name' => 'EI Link Lead', 'phone' => '96' . random_int(10000000, 99999999),
            'status_id' => (int) $this->db->selectValue('SELECT MIN(id) FROM lead_statuses'),
        ]);

        self::assertSame("/leads/{$leadPid}#notes", $repo->targetUrl('x', 'lead', $leadId, 'notes'));
        self::assertNull($repo->targetUrl('x', 'lead', 999999999, null), 'a deleted record has no target');
        self::assertNull($repo->targetUrl('x', 'not_a_type', 1, null), 'unknown link types are not followed');
        self::assertSame('/admin/cron', $repo->targetUrl('cron_alert', null, null, null));
        self::assertSame('/exports', $repo->targetUrl('export_ready', 'export', 5, null));
        self::assertSame('/enquiries/7', $repo->targetUrl('enquiry_new', 'enquiry', 7, null));
    }

    public function test_nobody_can_open_or_mark_someone_elses_notification(): void
    {
        $owner = $this->user('manager');
        $n = $this->notify($owner);
        $this->actAs($this->user('manager'));

        self::assertSame(404, $this->code('GET', "/notifications/{$n}/open"));
        self::assertNull($this->db->selectValue('SELECT read_at FROM notifications WHERE id = ?', [$n]));

        $this->send('POST', '/notifications/read-all', ['_token' => $this->token]);
        self::assertNull($this->db->selectValue('SELECT read_at FROM notifications WHERE id = ?', [$n]), 'mark-all only touches my own');
    }

    public function test_mark_all_read_and_the_bell_badge(): void
    {
        $me = $this->user('manager');
        $this->notify($me);
        $this->notify($me);
        $this->actAs($me);

        self::assertStringContainsString('2 unread', $this->send('GET', '/notifications')->getBody());
        self::assertStringContainsString('aria-label="Notifications, 2 unread"', $this->send('GET', '/dashboard')->getBody());

        $this->send('POST', '/notifications/read-all', ['_token' => $this->token]);

        self::assertStringContainsString('all caught up', $this->send('GET', '/notifications')->getBody());
        self::assertStringNotContainsString('unread"', $this->send('GET', '/dashboard')->getBody());
    }

    public function test_a_new_public_enquiry_notifies_the_people_who_run_the_inbox(): void
    {
        $manager = $this->user('manager');
        $accounts = $this->user('accounts');

        $pub = bin2hex(random_bytes(32));
        $this->store->sessions[$pub] = ['data' => ['_token' => 'tok', '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $ip = '198.51.100.' . random_int(1, 250);
        $this->router->dispatch(new Request([], ['name' => 'EI Notifier', 'phone' => '9876501234', 'message' => 'Please call me back', '_token' => 'tok'], ['crm_session' => $pub], [], [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact', 'REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
        $this->db->affectingStatement('DELETE FROM rate_limits WHERE bucket_key LIKE ?', ['%' . $ip . '%']);

        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'enquiry_new' AND title LIKE '% from EI Notifier'", [$manager]));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'enquiry_new'", [$accounts]));
        $this->db->affectingStatement("DELETE FROM notifications WHERE type = 'enquiry_new' AND title LIKE '% from EI Notifier'");
    }
}
