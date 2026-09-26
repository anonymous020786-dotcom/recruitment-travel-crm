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
use App\Repositories\CmsPageRepository;
use App\Repositories\UserRepository;
use App\Services\CmsPageService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Website pages: writing, the workflow, versions, scheduling, preview links, what the public sees, and who may do what. */
final class CmsPagesTest extends DbTestCase
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
        $this->db->affectingStatement("DELETE FROM cms_pages WHERE path LIKE 'tcms-%' OR path LIKE 'tcms/%'");
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'CMS branch', 'code' => 'CMX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM cms_pages WHERE path LIKE 'tcms-%' OR path LIKE 'tcms/%' OR title LIKE 'TCMS %'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph}) OR type = 'cms_review'", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'cms'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['CMX-%']);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "TCMS {$role}", 'email' => 'tcms_' . bin2hex(random_bytes(4)) . '@dev.local',
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

    private function svc(): CmsPageService
    {
        return $this->app->get(CmsPageService::class);
    }

    private function repo(): CmsPageRepository
    {
        return $this->app->get(CmsPageRepository::class);
    }

    /** @var User cached actors */
    private ?User $publisher = null;
    private ?User $writer = null;

    private function publisher(): User
    {
        return $this->publisher ??= $this->model($this->user('admin'));
    }

    private function writer(): User
    {
        return $this->writer ??= $this->model($this->user('manager'));
    }

    /** @param array<string,mixed> $over */
    private function input(array $over = []): array
    {
        return $over + [
            'title' => 'TCMS Visa services', 'path' => 'tcms-visa', 'summary' => 'How we help', 'template' => 'default',
            'body' => "Intro paragraph about visas.\n\n## Requirements\n\n- Passport\n- Photos\n\nSee [jobs](/overseas-jobs).",
            'meta_title' => 'Visa services for overseas workers', 'meta_description' => 'Everything about visas.', 'in_sitemap' => '1',
        ];
    }

    /** @param array<string,mixed> $over */
    private function make(array $over = [], ?User $by = null): string
    {
        return $this->svc()->create($this->input($over), $by ?? $this->writer());
    }

    private function row(string $publicId): array
    {
        return $this->repo()->find($publicId) ?? [];
    }

    private function live(string $path): ?array
    {
        return $this->repo()->live($path);
    }

    private function errorsOf(callable $do, string $label = ''): array
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail("a validation error was expected for {$label}");
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

    /** @param array<string,mixed> $post @param array<string,string> $headers */
    private function send(string $method, string $uri, array $post = [], array $headers = []): Response
    {
        if ($post !== [] && !isset($post['_token']) && $this->token !== '') {
            $post['_token'] = $this->token;
        }
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost'];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->router->dispatch(new Request($query, $post, $this->sid !== '' ? ['crm_session' => $this->sid] : [], [], $server, ''));
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

    // ---- creating and editing ---------------------------------------------------------------------------------------

    public function test_a_page_is_created_as_a_draft_with_its_first_version(): void
    {
        $id = $this->make();
        $p = $this->row($id);
        self::assertSame('draft', $p['status']);
        self::assertSame('tcms-visa', $p['path']);
        self::assertSame(1, (int) $p['version']);
        self::assertStringContainsString('<h2 id="requirements">Requirements</h2>', $p['body_html']);
        self::assertGreaterThan(5, (int) $p['word_count']);
        self::assertNull($this->live('tcms-visa'), 'a draft is never public');
        $revs = $this->repo()->revisions((int) $p['id']);
        self::assertCount(1, $revs);
        self::assertSame('Created', $revs[0]['note']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE module = 'cms' AND action = 'cms_page_created'"));
    }

    public function test_an_empty_address_is_made_from_the_title_and_made_unique(): void
    {
        $a = $this->row($this->make(['path' => '', 'title' => 'TCMS Hello World Page']));
        self::assertSame('tcms-hello-world-page', $a['path']);
        $errors = $this->errorsOf(fn () => $this->make(['path' => '', 'title' => 'TCMS Hello World Page']));
        self::assertArrayHasKey('path', $errors, 'the same title gives the same address: refused, never silently altered');
        $nonLatin = $this->row($this->make(['path' => '', 'title' => 'وظائف']));
        self::assertMatchesRegularExpression('/^page-[a-z0-9]{6}$/', $nonLatin['path'], 'a title with nothing to slug still gets an address');
        $this->db->affectingStatement('DELETE FROM cms_pages WHERE id = :i', ['i' => $nonLatin['id']]);
    }

    public function test_addresses_are_validated_and_system_paths_are_reserved(): void
    {
        foreach (['Has Spaces', 'a/b//c/d/e', 'a/b/c/d', 'bad_underscore', 'tcms-' . str_repeat('x', 160), '../etc', 'a/./b', 'tcms--double', '-lead', 'trail-'] as $bad) {
            $errors = $this->errorsOf(fn () => $this->make(['path' => $bad, 'title' => 'TCMS bad']), $bad);
            self::assertArrayHasKey('path', $errors, $bad);
        }
        // first segments a route or crawler already uses
        foreach (['admin', 'admin/pages', 'overseas-jobs', 'blog', 'about', 'contact', 'login', 'assets', 'pay', 'webhooks', 'preview', 'sitemap.xml', 'leads', 'dashboard', 'ROBOTS.TXT'] as $reserved) {
            $errors = $this->errorsOf(fn () => $this->make(['path' => $reserved, 'title' => 'TCMS reserved']), $reserved);
            self::assertArrayHasKey('path', $errors, $reserved);
        }
        $this->make(['path' => '/tcms-slash/']);   // surrounding slashes and case are tidied
        self::assertNotNull($this->repo()->live('tcms-slash') ?? $this->db->selectOne("SELECT 1 FROM cms_pages WHERE path = 'tcms-slash'"));
        $this->make(['path' => 'tcms/a/b', 'title' => 'TCMS nested']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_pages WHERE path = 'tcms/a/b'"));
    }

    public function test_the_fields_are_validated(): void
    {
        $cases = [
            'title' => ['title' => ''], 'body' => ['body' => '   '], 'summary' => ['summary' => str_repeat('x', 301)],
            'template' => ['template' => 'fancy'], 'canonical_url' => ['canonical_url' => 'http://insecure.example/'], 'featured_image' => ['featured_image' => 'javascript:alert(1)'],
            'robots' => ['robots' => 'maybe'], 'sitemap_priority' => ['sitemap_priority' => '2.5'], 'sitemap_changefreq' => ['sitemap_changefreq' => 'hourly'],
            'meta_title' => ['meta_title' => str_repeat('x', 121)], 'meta_description' => ['meta_description' => "two\nlines"], 'faq' => ['faq_q' => ['Only a question'], 'faq_a' => ['']],
        ];
        foreach ($cases as $field => $over) {
            self::assertArrayHasKey($field, $this->errorsOf(fn () => $this->make($over + ['path' => 'tcms-v-' . $field])), $field);
        }
        self::assertArrayHasKey('body', $this->errorsOf(fn () => $this->make(['body' => "bad \x01 byte"])));
        self::assertArrayHasKey('body', $this->errorsOf(fn () => $this->make(['body' => str_repeat('x', 100001)])));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_pages WHERE path LIKE 'tcms-v-%'"), 'nothing was saved by any refused call');
    }

    public function test_saving_bumps_the_version_records_a_revision_and_detects_conflicts(): void
    {
        $id = $this->make();
        $svc = $this->svc();
        self::assertSame([], $svc->update($id, $this->input(['version' => 1]), $this->writer()), 'saving unchanged content changes nothing');
        self::assertSame(1, (int) $this->row($id)['version']);

        $changed = $svc->update($id, $this->input(['version' => 1, 'title' => 'TCMS Visa help', 'body' => "New text.\n\n## New"]), $this->writer());
        self::assertContains('title', $changed);
        self::assertContains('body_source', $changed);
        $p = $this->row($id);
        self::assertSame(2, (int) $p['version']);
        self::assertSame('TCMS Visa help', $p['title']);
        self::assertStringContainsString('id="new"', $p['body_html']);
        self::assertCount(2, $this->repo()->revisions((int) $p['id']));

        try {
            $svc->update($id, $this->input(['version' => 1, 'title' => 'TCMS Stale edit']), $this->writer());
            self::fail('a stale edit must be refused');
        } catch (DomainRuleException $e) {
            self::assertSame(409, $e->httpStatus());
            self::assertStringContainsString('version 2', $e->getMessage());
        }
        self::assertSame('TCMS Visa help', $this->row($id)['title']);
    }

    public function test_only_the_newest_fifty_versions_are_kept(): void
    {
        $id = $this->make();
        for ($v = 1; $v <= 54; $v++) {
            $this->svc()->update($id, $this->input(['version' => $v, 'body' => "Body number {$v}."]), $this->writer());
        }
        $p = $this->row($id);
        self::assertSame(55, (int) $p['version']);
        $revs = $this->repo()->revisions((int) $p['id']);
        self::assertCount(CmsPageService::MAX_REVISIONS, $revs);
        self::assertSame(55, (int) $revs[0]['version']);
        self::assertSame(6, (int) $revs[count($revs) - 1]['version']);
    }

    public function test_the_address_is_frozen_once_a_page_has_been_published(): void
    {
        $id = $this->make();
        $this->svc()->update($id, $this->input(['version' => 1, 'path' => 'tcms-renamed']), $this->writer());
        self::assertSame('tcms-renamed', $this->row($id)['path'], 'free to change before publishing');
        $this->svc()->publish($id, $this->publisher());
        $errors = $this->errorsOf(fn () => $this->svc()->update($id, $this->input(['version' => 2, 'path' => 'tcms-again']), $this->publisher()));
        self::assertArrayHasKey('path', $errors);
        $this->svc()->update($id, $this->input(['version' => 2, 'path' => 'tcms-renamed', 'title' => 'TCMS Same path']), $this->publisher());
        self::assertSame('tcms-renamed', $this->row($id)['path']);
    }

    public function test_faq_pairs_are_stored_and_blank_rows_ignored(): void
    {
        $id = $this->make(['faq_q' => ['How long?', '', 'Cost?'], 'faq_a' => ['Two weeks', '', "Free\nfor candidates"]]);
        $faq = json_decode((string) $this->row($id)['faq'], true);
        self::assertSame([['q' => 'How long?', 'a' => 'Two weeks'], ['q' => 'Cost?', 'a' => "Free\nfor candidates"]], $faq);
        $none = $this->make(['path' => 'tcms-nofaq', 'faq_q' => ['', ''], 'faq_a' => ['', '']]);
        self::assertNull($this->row($none)['faq']);
        $tooMany = $this->errorsOf(fn () => $this->make(['path' => 'tcms-manyfaq', 'faq_q' => array_fill(0, 21, 'q'), 'faq_a' => array_fill(0, 21, 'a')]));
        self::assertArrayHasKey('faq', $tooMany);
    }

    // ---- the workflow ------------------------------------------------------------------------------------------------

    public function test_the_review_workflow_and_who_may_publish(): void
    {
        $id = $this->make();
        $publisher = $this->publisher();
        $writer = $this->writer();

        $this->svc()->submitForReview($id, $writer);
        self::assertSame('review', $this->row($id)['status']);
        self::assertGreaterThanOrEqual(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE type = 'cms_review' AND user_id = :u", ['u' => $publisher->id]), 'publishers are told');
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE type = 'cms_review' AND user_id = :u", ['u' => $writer->id]), 'not the submitter');

        try {
            $this->svc()->publish($id, $writer);
            self::fail('a writer cannot publish');
        } catch (AuthorizationException) {
            self::assertSame('review', $this->row($id)['status']);
        }
        $this->svc()->sendBack($id, $publisher);
        self::assertSame('draft', $this->row($id)['status']);
        $this->svc()->submitForReview($id, $writer);
        $this->svc()->publish($id, $publisher);
        $p = $this->row($id);
        self::assertSame('published', $p['status']);
        self::assertNotNull($p['published_at']);
        self::assertNotNull($this->live('tcms-visa'));

        // the invalid moves
        foreach ([fn () => $this->svc()->submitForReview($id, $writer), fn () => $this->svc()->sendBack($id, $publisher), fn () => $this->svc()->publish($id, $publisher), fn () => $this->svc()->restoreArchived($id, $writer)] as $bad) {
            try {
                $bad();
                self::fail('an invalid transition must be refused');
            } catch (DomainRuleException $e) {
                self::assertSame(422, $e->httpStatus());
            }
        }
        $this->svc()->unpublish($id, $publisher);
        self::assertNull($this->live('tcms-visa'));
        $this->svc()->archive($id, $publisher);
        self::assertSame('archived', $this->row($id)['status']);
        $this->svc()->restoreArchived($id, $writer);
        self::assertSame('draft', $this->row($id)['status']);
        $firstPublished = $p['published_at'];
        $this->svc()->publish($id, $publisher);
        self::assertSame($firstPublished, $this->row($id)['published_at'], 'the first publication date is kept');
    }

    public function test_a_writer_cannot_change_a_page_that_is_live(): void
    {
        $id = $this->make();
        $this->svc()->publish($id, $this->publisher());
        try {
            $this->svc()->update($id, $this->input(['version' => 1, 'title' => 'TCMS Sneaky']), $this->writer());
            self::fail('live content changes only with a publisher');
        } catch (AuthorizationException) {
            self::assertSame('TCMS Visa services', $this->row($id)['title']);
        }
        $this->svc()->update($id, $this->input(['version' => 1, 'title' => 'TCMS Approved']), $this->publisher());
        self::assertSame('TCMS Approved', $this->row($id)['title']);
        try {
            $this->svc()->trash($id, $this->writer());
            self::fail('taking a live page off the site is a publisher decision');
        } catch (AuthorizationException) {
            self::assertNull($this->row($id)['deleted_at']);
        }
    }

    public function test_publishing_can_be_scheduled_and_a_page_can_be_taken_down_automatically(): void
    {
        $id = $this->make();
        $future = gmdate('Y-m-d\TH:i', time() + 3 * 86400);
        $this->svc()->publish($id, $this->publisher(), $future);
        $p = $this->row($id);
        self::assertSame('scheduled', $p['state']);
        self::assertNull($this->live('tcms-visa'), 'not public before its time');

        $this->db->affectingStatement("UPDATE cms_pages SET publish_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = :i", ['i' => $p['id']]);
        self::assertNotNull($this->live('tcms-visa'), 'public as soon as the time passes — no cron needed');
        self::assertSame('live', $this->row($id)['state']);

        $this->db->affectingStatement("UPDATE cms_pages SET unpublish_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = :i", ['i' => $p['id']]);
        self::assertNull($this->live('tcms-visa'), 'gone as soon as its take-down time passes');
        self::assertSame('expired', $this->row($id)['state']);
    }

    public function test_schedule_times_are_validated_and_read_in_the_business_time_zone(): void
    {
        $id = $this->make();
        $bad = ['not a date', '2030-13-01T10:00', '2030-02-30T10:00', gmdate('Y-m-d\TH:i', time() - 86400)];
        foreach ($bad as $when) {
            $errors = $this->errorsOf(fn () => $this->svc()->publish($id, $this->publisher(), $when));
            self::assertArrayHasKey('publish_at', $errors, $when);
        }
        self::assertSame('draft', $this->row($id)['status']);

        $tz = new \DateTimeZone((string) config('app.timezone', 'UTC'));
        $local = (new \DateTimeImmutable('+2 days 10:30', $tz))->format('Y-m-d\TH:i');
        $expected = (new \DateTimeImmutable($local, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        self::assertSame($expected, $this->svc()->parseLocal($local));
        $this->svc()->publish($id, $this->publisher(), $local);
        self::assertSame($expected, $this->row($id)['publish_at']);

        // a take-down before the go-live time is refused, in the editor and when publishing
        $window = $this->errorsOf(fn () => $this->svc()->update($id, $this->input(['version' => 1, 'publish_at' => $local, 'unpublish_at' => (new \DateTimeImmutable('+1 day', $tz))->format('Y-m-d\TH:i')]), $this->publisher()));
        self::assertArrayHasKey('unpublish_at', $window);
    }

    public function test_the_trash_holds_pages_until_they_are_restored_or_deleted_for_good(): void
    {
        $id = $this->make();
        $this->svc()->trash($id, $this->writer());
        self::assertSame('trash', $this->row($id)['state']);
        self::assertNull($this->live('tcms-visa'));
        self::assertTrue($this->repo()->pathExists('tcms-visa'), 'a trashed page keeps its address so a restore is always safe');
        self::assertArrayHasKey('path', $this->errorsOf(fn () => $this->make()));
        foreach ([fn () => $this->svc()->trash($id, $this->writer()), fn () => $this->svc()->update($id, $this->input(['version' => 1]), $this->writer()), fn () => $this->svc()->submitForReview($id, $this->writer())] as $bad) {
            try {
                $bad();
                self::fail('a page in the trash cannot be changed');
            } catch (DomainRuleException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->svc()->purge($id, $this->writer());
            self::fail('only a publisher may delete for good');
        } catch (AuthorizationException) {
            self::assertNotNull($this->row($id));
        }
        $this->svc()->restoreFromTrash($id, $this->writer());
        self::assertSame('draft', $this->row($id)['state']);
        try {
            $this->svc()->purge($id, $this->publisher());
            self::fail('a page that is not in the trash cannot be purged');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $this->svc()->trash($id, $this->writer());
        $this->svc()->purge($id, $this->publisher());
        self::assertSame([], $this->row($id));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_revisions r LEFT JOIN cms_pages p ON p.id = r.page_id WHERE p.id IS NULL'), 'the history goes with it');

        $old = $this->make(['path' => 'tcms-old']);
        $this->svc()->trash($old, $this->writer());
        $this->db->affectingStatement("UPDATE cms_pages SET deleted_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE public_id = :p", ['p' => $old]);
        self::assertGreaterThanOrEqual(1, $this->repo()->purgeTrash(30));
        self::assertSame([], $this->row($old));
    }

    public function test_duplicate_makes_an_independent_draft_copy(): void
    {
        $id = $this->make(['faq_q' => ['Q?'], 'faq_a' => ['A.'], 'focus_keyword' => 'visa']);
        $this->svc()->publish($id, $this->publisher());
        $copyId = $this->svc()->duplicate($id, $this->writer());
        $copy = $this->row($copyId);
        self::assertSame('draft', $copy['status']);
        self::assertSame('tcms-visa-copy', $copy['path']);
        self::assertSame('Copy of TCMS Visa services', $copy['title']);
        self::assertNull($copy['published_at']);
        self::assertNull($copy['publish_at']);
        self::assertSame($this->row($id)['body_source'], $copy['body_source']);
        self::assertSame('visa', $copy['focus_keyword']);
        self::assertNotNull($copy['faq']);
        self::assertSame('tcms-visa-copy-2', $this->row($this->svc()->duplicate($id, $this->writer()))['path']);
        self::assertNull($this->live('tcms-visa-copy'), 'a copy is a draft');
    }

    public function test_bulk_actions_judge_each_page_on_its_own(): void
    {
        $a = $this->make(['path' => 'tcms-b1', 'title' => 'TCMS B1']);
        $b = $this->make(['path' => 'tcms-b2', 'title' => 'TCMS B2']);
        $this->svc()->publish($b, $this->publisher());

        $r = $this->svc()->bulk('publish', [$a, $b, 'NOTAREALID', $a], $this->publisher());
        self::assertSame(1, $r['done'], 'B1 published; B2 is already live; junk ids and duplicates are ignored');
        self::assertCount(1, $r['skipped']);
        self::assertStringContainsString('TCMS B2', $r['skipped'][0]);

        $r = $this->svc()->bulk('publish', [$a], $this->writer());
        self::assertSame(0, $r['done']);
        self::assertCount(1, $r['skipped'], 'a writer cannot bulk-publish');

        self::assertSame(2, $this->svc()->bulk('trash', [$a, $b], $this->publisher())['done']);
        self::assertSame(2, $this->svc()->bulk('untrash', [$a, $b], $this->publisher())['done']);
        self::assertSame(2, $this->svc()->bulk('unpublish', [$a, $b], $this->publisher())['done']);

        foreach ([['nonsense', [$a]], ['publish', []], ['publish', ['bad']], ['publish', array_map(static fn (int $i): string => Ulid::generate(), range(1, 101))]] as [$action, $ids]) {
            self::assertNotSame([], $this->errorsOf(fn () => $this->svc()->bulk($action, $ids, $this->publisher())), $action);
        }
    }

    // ---- versions --------------------------------------------------------------------------------------------------

    public function test_an_old_version_can_be_compared_and_put_back_without_touching_the_address(): void
    {
        $id = $this->make(['body' => "Line one.\n\nLine two."]);
        $this->svc()->update($id, $this->input(['version' => 1, 'body' => "Line one.\n\nLine two changed.\n\nLine three.", 'meta_title' => 'Changed search title for the page']), $this->writer());

        $diff = $this->svc()->compare($id, 1, 2);
        $ops = array_column($diff, 'op');
        self::assertContains('-', $ops);
        self::assertContains('+', $ops);
        self::assertSame([], array_filter($this->svc()->compare($id, 2, 0), static fn (array $d): bool => $d['op'] !== '='), 'version 0 means the current page');

        $this->svc()->restoreRevision($id, 1, $this->writer());
        $p = $this->row($id);
        self::assertSame(3, (int) $p['version']);
        self::assertSame("Line one.\n\nLine two.", $p['body_source']);
        self::assertSame('Visa services for overseas workers', $p['meta_title'], 'the settings come back too');
        self::assertSame('tcms-visa', $p['path']);
        $revs = $this->repo()->revisions((int) $p['id']);
        self::assertSame('Restored from version 1', $revs[0]['note']);

        $this->db->affectingStatement("UPDATE cms_revisions SET snapshot = JSON_SET(snapshot, '$.path', 'tcms-elsewhere') WHERE page_id = :p AND version = 1", ['p' => $p['id']]);
        $this->svc()->restoreRevision($id, 1, $this->writer());
        self::assertSame('tcms-visa', $this->row($id)['path'], 'an address never travels back in time');

        try {
            $this->svc()->restoreRevision($id, 99, $this->writer());
            self::fail('an unknown version must be refused');
        } catch (DomainRuleException $e) {
            self::assertSame(404, $e->httpStatus());
        }
    }

    // ---- preview links ---------------------------------------------------------------------------------------------

    public function test_a_preview_link_shows_an_unpublished_page_until_it_expires_or_is_revoked(): void
    {
        $id = $this->make();
        $token = $this->svc()->createPreviewLink($id, $this->writer());
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $token);
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_preview_tokens WHERE token_hash = :t', ['t' => $token]), 'the token itself is never stored');
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_preview_tokens WHERE token_hash = :t', ['t' => hash('sha256', $token)]));

        self::assertSame($id, $this->svc()->pageForPreview($token)['public_id']);
        foreach (['', 'short', str_repeat('g', 48), strtoupper($token), $token . 'x', str_repeat('0', 48)] as $bad) {
            self::assertNull($this->svc()->pageForPreview($bad), $bad);
        }

        $this->db->affectingStatement('UPDATE cms_preview_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        self::assertNull($this->svc()->pageForPreview($token), 'expired');
        $fresh = $this->svc()->createPreviewLink($id, $this->writer());
        self::assertSame(2, $this->svc()->revokePreviewLinks($id, $this->writer()), 'revoking removes every link for the page, expired or not');
        self::assertNull($this->svc()->pageForPreview($fresh), 'revoked');

        $last = '';
        for ($i = 0; $i < CmsPageService::MAX_PREVIEW_LINKS; $i++) {
            $last = $this->svc()->createPreviewLink($id, $this->writer());
        }
        try {
            $this->svc()->createPreviewLink($id, $this->writer());
            self::fail('the cap on active links must hold');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString((string) CmsPageService::MAX_PREVIEW_LINKS, $e->getMessage());
        }
        self::assertNotNull($this->svc()->pageForPreview($last));
        $this->svc()->trash($id, $this->writer());
        self::assertNull($this->svc()->pageForPreview($last), 'a link to a page in the trash shows nothing');
    }

    // ---- what the public sees --------------------------------------------------------------------------------------

    public function test_the_public_site_serves_only_live_pages_at_their_address(): void
    {
        $id = $this->make(['faq_q' => ['How long?'], 'faq_a' => ['Two weeks.']]);
        self::assertSame(404, $this->code('GET', '/tcms-visa'), 'a draft');
        $this->svc()->publish($id, $this->publisher());

        $res = $this->send('GET', '/tcms-visa');
        self::assertSame(200, $res->getStatus());
        $body = $res->getBody();
        self::assertStringContainsString('TCMS Visa services', $body);
        self::assertStringContainsString('Requirements', $body);
        self::assertStringContainsString('How long?', $body);
        self::assertStringContainsString('FAQPage', $body);
        self::assertStringContainsString('BreadcrumbList', $body);
        self::assertStringContainsString('rel="canonical"', $body);
        self::assertStringContainsString('public, max-age=300', (string) $res->getHeader('Cache-Control'));
        self::assertStringContainsString('Visa services for overseas workers', $body, 'the search title is used');
        self::assertStringNotContainsString('noindex', $body);

        // conditional GET
        $etag = (string) $res->getHeader('ETag');
        self::assertNotSame('', $etag);
        self::assertSame(304, $this->send('GET', '/tcms-visa', [], ['If-None-Match' => $etag])->getStatus());

        // odd addresses are plain 404s
        foreach (['/tcms-nothing', '/TCMS-VISA', '/tcms-visa/extra', '/tcms--visa', '/tcms_visa', '/%2e%2e/etc/passwd', '/tcms-visa.php'] as $url) {
            self::assertSame(404, $this->code('GET', $url), $url);
        }
        // a real route still wins, and a wrong method on a real route is still a 405, not a page lookup
        self::assertSame(200, $this->code('GET', '/about'));
        self::assertSame(405, $this->code('GET', '/pay/abc/go'));

        $this->svc()->unpublish($id, $this->publisher());
        self::assertSame(404, $this->code('GET', '/tcms-visa'));
    }

    public function test_seo_settings_reach_the_page_head(): void
    {
        $id = $this->make(['robots' => 'noindex', 'canonical_url' => 'https://example.org/original', 'og_title' => 'Share title here', 'og_description' => 'Share description here', 'featured_image' => 'https://cdn.example.com/hero.jpg', 'featured_alt' => 'Hero']);
        $this->svc()->publish($id, $this->publisher());
        $body = $this->send('GET', '/tcms-visa')->getBody();
        self::assertStringContainsString('content="noindex,follow"', $body);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/original">', $body);
        self::assertStringContainsString('content="Share title here"', $body);
        self::assertStringContainsString('content="Share description here"', $body);
        self::assertStringContainsString('https://cdn.example.com/hero.jpg', $body);
        self::assertStringContainsString('alt="Hero"', $body);
    }

    public function test_templates_change_the_chrome_and_width(): void
    {
        $landing = $this->make(['path' => 'tcms-land', 'title' => 'TCMS Landing', 'template' => 'landing']);
        $wide = $this->make(['path' => 'tcms-wide', 'title' => 'TCMS Wide', 'template' => 'wide']);
        $std = $this->make(['path' => 'tcms-std', 'title' => 'TCMS Std']);
        foreach ([$landing, $wide, $std] as $id) {
            $this->svc()->publish($id, $this->publisher());
        }
        self::assertStringNotContainsString('aria-label="Primary"', $this->send('GET', '/tcms-land')->getBody(), 'a landing page has no menu');
        self::assertStringNotContainsString('aria-label="Footer"', $this->send('GET', '/tcms-land')->getBody());
        self::assertStringContainsString('aria-label="Primary"', $this->send('GET', '/tcms-std')->getBody());
        self::assertStringContainsString('max-w-6xl', $this->send('GET', '/tcms-wide')->getBody());
        self::assertStringContainsString('max-w-3xl', $this->send('GET', '/tcms-std')->getBody());
    }

    public function test_shortcodes_are_filled_in_when_the_page_is_shown(): void
    {
        $body = "## First\n\n{{toc}}\n\n## Second\n\n{{contact:Talk to us}}\n\n{{button:/overseas-jobs|Browse jobs}}\n\n{{jobs:3}}\n\n{{packages:2}}\n\n{{youtube:dQw4w9WgXcQ}}\n\n{{phone}}\n\n{{faq}}";
        $id = $this->make(['body' => $body, 'faq_q' => ['Why <us>?'], 'faq_a' => ['Because.']]);
        $this->svc()->publish($id, $this->publisher());
        $html = $this->send('GET', '/tcms-visa')->getBody();
        self::assertStringContainsString('aria-label="On this page"', $html);
        self::assertStringContainsString('href="#second"', $html);
        self::assertStringContainsString('Talk to us', $html);
        self::assertStringContainsString('href="/overseas-jobs"', $html);
        self::assertStringContainsString('Browse jobs', $html);
        self::assertStringContainsString('youtube.com/watch?v=dQw4w9WgXcQ', $html);
        self::assertStringContainsString('Why &lt;us&gt;?', $html);
        self::assertSame(1, substr_count($html, 'Frequently asked questions</h2>'), 'the FAQ block appears once, where {{faq}} put it');
        self::assertStringNotContainsString('cms-sc', $html, 'no unfilled placeholder is left behind');
        self::assertStringNotContainsString('{{', $html);
    }

    public function test_the_sitemap_lists_only_indexable_live_pages_with_their_settings(): void
    {
        $live = $this->make(['path' => 'tcms-sm-live', 'title' => 'TCMS SM live', 'sitemap_priority' => '0.8', 'sitemap_changefreq' => 'weekly']);
        $hidden = $this->make(['path' => 'tcms-sm-off', 'title' => 'TCMS SM off', 'in_sitemap' => '']);
        $noindex = $this->make(['path' => 'tcms-sm-noindex', 'title' => 'TCMS SM noindex', 'robots' => 'noindex']);
        $draft = $this->make(['path' => 'tcms-sm-draft', 'title' => 'TCMS SM draft']);
        foreach ([$live, $hidden, $noindex] as $id) {
            $this->svc()->publish($id, $this->publisher());
        }
        $xml = $this->send('GET', '/sitemap.xml')->getBody();
        self::assertStringContainsString('/tcms-sm-live</loc>', $xml);
        self::assertMatchesRegularExpression('#/tcms-sm-live</loc><lastmod>\d{4}-\d{2}-\d{2}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority>#', $xml);
        foreach (['tcms-sm-off', 'tcms-sm-noindex', 'tcms-sm-draft'] as $no) {
            self::assertStringNotContainsString($no, $xml, $no);
        }
        self::assertStringContainsString('/about</loc>', $xml, 'the fixed pages are still there');
        unset($draft);
    }

    public function test_the_preview_link_route_shows_the_page_privately(): void
    {
        $id = $this->make();
        $token = $this->svc()->createPreviewLink($id, $this->writer());
        $res = $this->send('GET', '/preview/' . $token);
        self::assertSame(200, $res->getStatus());
        self::assertStringContainsString('TCMS Visa services', $res->getBody());
        self::assertStringContainsString('Preview', $res->getBody());
        self::assertStringContainsString('noindex', (string) $res->getHeader('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $res->getHeader('Cache-Control'));
        self::assertStringContainsString('noindex,nofollow', $res->getBody());
        self::assertStringNotContainsString('BreadcrumbList', $res->getBody(), 'no page structured data for a preview');
        self::assertSame(404, $this->code('GET', '/preview/' . str_repeat('a', 48)));
        self::assertSame(404, $this->code('GET', '/preview/nonsense'));
    }

    // ---- the admin screens -----------------------------------------------------------------------------------------

    public function test_access_is_view_manage_publish(): void
    {
        $id = $this->make();
        $urls = ['/admin/cms', "/admin/cms/{$id}/edit", "/admin/cms/{$id}/revisions", "/admin/cms/{$id}/preview"];

        $this->actAs($this->user('read_only'));
        foreach ($urls as $u) {
            self::assertSame(403, $this->code('GET', $u), $u);
        }
        self::assertSame(403, $this->code('GET', '/admin/cms/create'));

        $this->actAs($this->writer()->id);
        foreach ($urls as $u) {
            self::assertSame(200, $this->code('GET', $u), $u);
        }
        self::assertSame(200, $this->code('GET', '/admin/cms/create'));
        self::assertSame(403, $this->code('POST', "/admin/cms/{$id}/publish", ['x' => '1']), 'a writer cannot publish');
        self::assertSame(403, $this->code('POST', "/admin/cms/{$id}/archive", ['x' => '1']));
        self::assertSame(403, $this->code('POST', "/admin/cms/{$id}/purge", ['x' => '1']));
        self::assertSame('draft', $this->row($id)['status']);

        $this->actAs($this->publisher()->id);
        self::assertSame(302, $this->code('POST', "/admin/cms/{$id}/publish", ['x' => '1']));
        self::assertSame('published', $this->row($id)['status']);
    }

    public function test_the_list_filters_by_status_and_searches(): void
    {
        $a = $this->make(['path' => 'tcms-l1', 'title' => 'TCMS Alpha page']);
        $b = $this->make(['path' => 'tcms-l2', 'title' => 'TCMS Beta page']);
        $c = $this->make(['path' => 'tcms-l3', 'title' => 'TCMS Gamma page']);
        $this->svc()->publish($b, $this->publisher());
        $this->svc()->trash($c, $this->writer());
        $this->actAs($this->publisher()->id);

        $all = $this->send('GET', '/admin/cms?q=TCMS')->getBody();
        self::assertStringContainsString('TCMS Alpha page', $all);
        self::assertStringContainsString('TCMS Beta page', $all);
        self::assertStringNotContainsString('TCMS Gamma page', $all, 'the trash is a tab of its own');
        self::assertStringContainsString('TCMS Gamma page', $this->send('GET', '/admin/cms?tab=trash&q=TCMS')->getBody());
        $live = $this->send('GET', '/admin/cms?tab=live&q=TCMS')->getBody();
        self::assertStringContainsString('TCMS Beta page', $live);
        self::assertStringNotContainsString('TCMS Alpha page', $live);
        self::assertStringContainsString('TCMS Alpha page', $this->send('GET', '/admin/cms?tab=draft&q=Alpha')->getBody());
        self::assertStringContainsString('No page matches', $this->send('GET', '/admin/cms?q=zzzzqqq')->getBody());
        self::assertSame(200, $this->code('GET', '/admin/cms?tab=nonsense&page=99999&q=' . str_repeat('x', 500)), 'junk parameters are tidied, not fatal');
        unset($a);
    }

    public function test_pages_can_be_created_edited_and_published_from_the_screens(): void
    {
        $this->actAs($this->publisher()->id);
        $res = $this->send('POST', '/admin/cms', $this->input(['path' => 'tcms-screen', 'title' => 'TCMS Screen page']));
        $location = (string) $res->getHeader('Location');
        self::assertMatchesRegularExpression('#^/admin/cms/[0-9A-Z]{26}/edit$#', $location);
        $id = explode('/', $location)[3];
        $edit = $this->send('GET', $location)->getBody();
        self::assertStringContainsString('TCMS Screen page', $edit);
        self::assertStringContainsString('SEO checklist', $edit);
        self::assertStringContainsString('name="version" value="1"', $edit);
        self::assertStringContainsString('How it looks in search', $edit);

        $bad = $this->send('POST', '/admin/cms', ['title' => '', 'body' => '']);
        self::assertSame('/admin/cms/create', $bad->getHeader('Location'));

        $this->send('PUT', "/admin/cms/{$id}", $this->input(['path' => 'tcms-screen', 'title' => 'TCMS Screen edited', 'version' => '1', '_method' => 'PUT']));
        self::assertSame('TCMS Screen edited', $this->row($id)['title']);
        $stale = $this->send('PUT', "/admin/cms/{$id}", $this->input(['path' => 'tcms-screen', 'title' => 'TCMS Stale', 'version' => '1', '_method' => 'PUT']));
        self::assertSame("/admin/cms/{$id}/edit", $stale->getHeader('Location'));
        self::assertSame('TCMS Screen edited', $this->row($id)['title'], 'the stale save changed nothing');

        $this->send('POST', "/admin/cms/{$id}/publish", ['publish_at' => '']);
        self::assertSame(200, $this->code('GET', '/tcms-screen'));
        $this->send('POST', "/admin/cms/{$id}/unpublish", ['x' => '1']);
        self::assertSame(404, $this->code('GET', '/tcms-screen'));
        $this->send('POST', "/admin/cms/{$id}/duplicate", ['x' => '1']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_pages WHERE path = 'tcms-screen-copy'"));
        $this->send('POST', "/admin/cms/{$id}/trash", ['x' => '1']);
        self::assertSame('trash', $this->row($id)['state']);
        $this->send('POST', "/admin/cms/{$id}/untrash", ['x' => '1']);
        $this->send('POST', "/admin/cms/{$id}/archive", ['x' => '1']);
        self::assertSame('archived', $this->row($id)['status']);
        self::assertSame(404, $this->code('GET', '/admin/cms/NOSUCHPAGE00000000000000/edit'));
    }

    public function test_bulk_revisions_preview_and_purge_from_the_screens(): void
    {
        $a = $this->make(['path' => 'tcms-s1', 'title' => 'TCMS S1']);
        $b = $this->make(['path' => 'tcms-s2', 'title' => 'TCMS S2']);
        $this->actAs($this->publisher()->id);

        $this->send('POST', '/admin/cms/bulk', ['action' => 'publish', 'ids' => [$a, $b]]);
        self::assertSame('published', $this->row($a)['status']);
        self::assertSame('published', $this->row($b)['status']);
        $none = $this->send('POST', '/admin/cms/bulk', ['action' => 'publish', 'ids' => []]);
        self::assertSame('/admin/cms', $none->getHeader('Location'));

        $this->send('PUT', "/admin/cms/{$a}", $this->input(['path' => 'tcms-s1', 'title' => 'TCMS S1 v2', 'version' => '1', '_method' => 'PUT', 'body' => 'Changed body text here.']));
        $page = $this->send('GET', "/admin/cms/{$a}/revisions?a=1&b=2")->getBody();
        self::assertStringContainsString('Version 1', $page);
        self::assertStringContainsString('added', $page);
        self::assertStringContainsString('Changed body text here.', $page);
        self::assertSame(200, $this->code('GET', "/admin/cms/{$a}/revisions?a=abc&b=-1&x[]=1"), 'junk comparison parameters are ignored');
        $this->send('POST', "/admin/cms/{$a}/revisions/1/restore", ['x' => '1']);
        self::assertSame(3, (int) $this->row($a)['version']);
        self::assertSame(404, $this->code('POST', "/admin/cms/{$a}/revisions/99/restore", ['x' => '1']));

        $preview = $this->send('GET', "/admin/cms/{$b}/preview");
        self::assertSame(200, $preview->getStatus());
        self::assertStringContainsString('noindex', (string) $preview->getHeader('X-Robots-Tag'));

        $link = $this->send('POST', "/admin/cms/{$b}/preview-link", ['x' => '1']);
        self::assertSame("/admin/cms/{$b}/edit", $link->getHeader('Location'));
        self::assertSame(1, $this->repo()->activePreviewTokens((int) $this->row($b)['id']));
        $this->send('POST', "/admin/cms/{$b}/preview-link/revoke", ['x' => '1']);
        self::assertSame(0, $this->repo()->activePreviewTokens((int) $this->row($b)['id']));

        // deleting for good asks for a fresh password confirmation
        $this->svc()->trash($b, $this->publisher());
        $this->actAs($this->publisher()->id, confirmed: false);
        $ask = $this->send('POST', "/admin/cms/{$b}/purge", ['x' => '1']);
        self::assertStringContainsString('confirm', (string) $ask->getHeader('Location'));
        self::assertNotSame([], $this->row($b));
        $this->actAs($this->publisher()->id);
        $this->send('POST', "/admin/cms/{$b}/purge", ['x' => '1']);
        self::assertSame([], $this->row($b));
    }

    public function test_every_change_is_audited(): void
    {
        $id = $this->make();
        $this->svc()->update($id, $this->input(['version' => 1, 'title' => 'TCMS Audited']), $this->writer());
        $this->svc()->submitForReview($id, $this->writer());
        $this->svc()->publish($id, $this->publisher());
        $this->svc()->unpublish($id, $this->publisher());
        $this->svc()->trash($id, $this->writer());
        $actions = array_column($this->db->select("SELECT action FROM activity_logs WHERE module = 'cms' ORDER BY id"), 'action');
        self::assertSame(['cms_page_created', 'cms_page_updated', 'cms_page_submitted', 'cms_page_published', 'cms_page_unpublished', 'cms_page_trashed'], $actions);
    }
}
