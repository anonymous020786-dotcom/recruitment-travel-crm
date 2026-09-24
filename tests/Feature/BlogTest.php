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
use App\Repositories\UserRepository;
use App\Services\BlogService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** The blog: writing and publishing rules, the staff screens, and what the public site can and cannot see. */
final class BlogTest extends DbTestCase
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
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'BL branch', 'code' => 'BLX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM blog_posts WHERE title LIKE 'BLT %' OR slug LIKE 'blt-%' OR slug LIKE 'post-%' OR slug LIKE 'a-title-%'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'blog' AND action LIKE 'blog\\_%'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['BLX-%']);
    }

    private function user(string $role, string $name = 'BL person'): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => $name, 'email' => 'bl_' . bin2hex(random_bytes(4)) . '@dev.local',
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

    private function service(): BlogService
    {
        return $this->app->get(BlogService::class);
    }

    /** @return array<string,mixed> */
    private function row(string $publicId): array
    {
        return $this->db->selectOne('SELECT * FROM blog_posts WHERE public_id = ?', [$publicId]) ?? self::fail('no such post');
    }

    /** @param array<string,mixed> $over */
    private function draft(User $actor, array $over = []): string
    {
        return $this->service()->create($over + ['title' => 'BLT Working in Dubai', 'body' => "## Getting started\n\nFirst **paragraph**."], $actor);
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

    // ---- writing -------------------------------------------------------------------------------------------------

    public function test_a_new_post_is_a_draft_with_generated_html_a_slug_and_an_audit_row(): void
    {
        $actor = $this->model($this->user('manager'));

        $id = $this->draft($actor);
        $row = $this->row($id);

        self::assertSame('draft', $row['status']);
        self::assertNull($row['published_at']);
        self::assertSame('blt-working-in-dubai', $row['slug']);
        self::assertSame('<h3>Getting started</h3><p>First <strong>paragraph</strong>.</p>', $row['body_html']);
        self::assertStringContainsString('**paragraph**', $row['body_source']);
        self::assertSame($actor->id, (int) $row['author_id']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'blog_created' AND record_id = ?", [$row['id']]));
    }

    public function test_slugs_are_unique_and_a_title_with_no_latin_letters_still_gets_one(): void
    {
        $actor = $this->model($this->user('manager'));

        $a = $this->row($this->draft($actor, ['title' => 'BLT Same title']));
        $b = $this->row($this->draft($actor, ['title' => 'BLT Same title']));
        $c = $this->row($this->draft($actor, ['title' => 'BLT Same title', 'slug' => 'blt-same-title']));
        $d = $this->row($this->draft($actor, ['title' => 'दुबई में नौकरी']));

        self::assertSame('blt-same-title', $a['slug']);
        self::assertSame('blt-same-title-2', $b['slug']);
        self::assertSame('blt-same-title-3', $c['slug']);
        self::assertMatchesRegularExpression('/^post-[a-z0-9]{6}$/', $d['slug']);
    }

    public function test_input_is_validated(): void
    {
        $actor = $this->model($this->user('manager'));

        foreach ([
            'title' => ['title' => '  '], 'long title' => ['title' => str_repeat('t', 181)], 'body' => ['body' => ' '],
            'huge body' => ['body' => str_repeat('b', 60001)], 'control chars' => ['body' => "bad\x07text"],
            'excerpt' => ['excerpt' => str_repeat('e', 301)], 'multiline excerpt' => ['excerpt' => "a\nb"],
            'slug caps' => ['slug' => 'Has Caps'], 'slug double hyphen' => ['slug' => 'a--b'], 'slug slash' => ['slug' => 'a/b'],
        ] as $label => $bad) {
            try {
                $this->service()->create($bad + ['title' => 'BLT ok', 'body' => 'ok'], $actor);
                self::fail("accepted invalid {$label}");
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors(), $label);
            }
        }
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM blog_posts WHERE title = 'BLT ok'"));
    }

    public function test_only_people_with_blog_manage_can_write(): void
    {
        $counselor = $this->model($this->user('counselor'));

        $this->expectException(AuthorizationException::class);
        $this->draft($counselor);
    }

    public function test_the_slug_is_editable_until_first_publication_and_frozen_after(): void
    {
        $actor = $this->model($this->user('manager'));
        $id = $this->draft($actor);

        $this->service()->update($id, ['title' => 'BLT Renamed', 'body' => 'New **body**', 'slug' => 'blt-renamed', 'excerpt' => 'Short.'], $actor);
        $row = $this->row($id);
        self::assertSame('blt-renamed', $row['slug']);
        self::assertSame('<p>New <strong>body</strong></p>', $row['body_html']);
        self::assertSame('Short.', $row['excerpt']);

        $this->service()->publish($id, $actor);
        $this->service()->update($id, ['title' => 'BLT Renamed again', 'body' => 'x', 'slug' => 'blt-something-else'], $actor);
        self::assertSame('blt-renamed', $this->row($id)['slug'], 'a published post keeps its URL');
        self::assertSame('BLT Renamed again', $this->row($id)['title']);
    }

    // ---- publishing ----------------------------------------------------------------------------------------------

    public function test_the_publishing_workflow_and_a_stable_published_date(): void
    {
        $actor = $this->model($this->user('manager'));
        $id = $this->draft($actor);

        $this->service()->publish($id, $actor);
        $first = $this->row($id);
        self::assertSame('published', $first['status']);
        self::assertNotNull($first['published_at']);
        $this->refused(fn () => $this->service()->publish($id, $actor), 'invalid_transition');

        $this->db->affectingStatement('UPDATE blog_posts SET published_at = ? WHERE id = ?', ['2024-01-02 03:04:05', $first['id']]);
        $this->service()->unpublish($id, $actor);
        self::assertSame('draft', $this->row($id)['status']);
        $this->refused(fn () => $this->service()->unpublish($id, $actor), 'invalid_transition');

        $this->service()->publish($id, $actor);
        self::assertSame('2024-01-02 03:04:05', $this->row($id)['published_at'], 'republishing keeps the original date');

        $this->service()->archive($id, $actor);
        self::assertSame('archived', $this->row($id)['status']);
        $this->refused(fn () => $this->service()->archive($id, $actor), 'invalid_transition');
        $this->refused(fn () => $this->service()->update($id, ['title' => 'BLT x', 'body' => 'x'], $actor), 'invalid_transition');
        $this->service()->unpublish($id, $actor);   // restore
        self::assertSame('draft', $this->row($id)['status']);

        foreach (['blog_published', 'blog_unpublished', 'blog_archived'] as $action) {
            self::assertGreaterThanOrEqual(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND record_id = ?', [$action, $first['id']]), $action);
        }
        try {
            $this->service()->publish('01ZZZZZZZZZZZZZZZZZZZZZZZZ', $actor);
            self::fail('published a post that does not exist');
        } catch (DomainRuleException $e) {
            self::assertSame(404, $e->httpStatus());
        }
    }

    // ---- the screens and the public site -------------------------------------------------------------------------------

    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => time()] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
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

    public function test_only_people_with_the_permission_reach_the_staff_screens(): void
    {
        $this->actAs($this->user('counselor'));
        self::assertSame(403, $this->code('GET', '/admin/blog'));
        self::assertSame(403, $this->code('GET', '/admin/blog/create'));
        self::assertSame(403, $this->code('POST', '/admin/blog', ['title' => 'BLT x', 'body' => 'x']));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM blog_posts WHERE title = 'BLT x'"));

        $this->actAs($this->user('manager'));
        self::assertSame(200, $this->code('GET', '/admin/blog'));
        self::assertSame(200, $this->code('GET', '/admin/blog/create'));
        self::assertSame(404, $this->code('GET', '/admin/blog/no-such-post/edit'));
    }

    public function test_writing_previewing_and_publishing_through_the_screens(): void
    {
        $this->actAs($this->user('manager', 'BL Author'));

        $res = $this->send('POST', '/admin/blog', ['title' => 'BLT Guide to medicals', 'slug' => '', 'excerpt' => '', 'body' => "## Steps\n\n- book\n- attend"]);
        self::assertSame(302, $res->getStatus());
        $id = (string) $this->db->selectValue("SELECT public_id FROM blog_posts WHERE title = 'BLT Guide to medicals'");
        self::assertSame("/admin/blog/{$id}/edit", $res->getHeader('Location'));

        $edit = $this->send('GET', "/admin/blog/{$id}/edit")->getBody();
        self::assertStringContainsString('<li>book</li>', $edit, 'the preview shows the generated HTML');
        self::assertStringContainsString('Publish', $edit);
        self::assertSame(302, $this->send('PUT', "/admin/blog/{$id}", ['_method' => 'PUT', 'title' => 'BLT Guide to medicals v2', 'body' => 'Edited'])->getStatus());
        self::assertSame('BLT Guide to medicals v2', $this->row($id)['title']);

        // A validation error keeps what was typed and says what is wrong.
        $this->send('PUT', "/admin/blog/{$id}", ['_method' => 'PUT', 'title' => '', 'body' => 'Kept text']);
        $page = $this->send('GET', "/admin/blog/{$id}/edit")->getBody();
        self::assertStringContainsString('Give the post a title', $page);
        self::assertStringContainsString('Kept text', $page);

        self::assertSame(404, $this->code('GET', '/blog/' . $this->row($id)['slug']), 'a draft is not public');
        self::assertSame(302, $this->send('POST', "/admin/blog/{$id}/publish", ['x' => '1'])->getStatus());
        self::assertSame('published', $this->row($id)['status']);
        self::assertSame(200, $this->code('GET', '/blog/' . $this->row($id)['slug']));
        self::assertStringContainsString('BLT Guide to medicals v2', $this->send('GET', '/admin/blog')->getBody());
    }

    public function test_the_public_site_shows_only_published_posts(): void
    {
        $actor = $this->model($this->user('manager', 'BL Author'));
        $live = $this->draft($actor, ['title' => 'BLT Live post', 'body' => "Hello <script>alert(1)</script> world.\n\nSee [jobs](/overseas-jobs).", 'excerpt' => 'A <b>short</b> summary']);
        $draft = $this->draft($actor, ['title' => 'BLT Draft post']);
        $archived = $this->draft($actor, ['title' => 'BLT Archived post']);
        $future = $this->draft($actor, ['title' => 'BLT Future post']);
        $this->service()->publish($live, $actor);
        $this->service()->publish($archived, $actor);
        $this->service()->archive($archived, $actor);
        $this->service()->publish($future, $actor);
        $this->db->affectingStatement('UPDATE blog_posts SET published_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 DAY) WHERE public_id = ?', [$future]);

        $this->actAs(null);
        $list = $this->send('GET', '/blog')->getBody();
        self::assertStringContainsString('BLT Live post', $list);
        foreach (['BLT Draft post', 'BLT Archived post', 'BLT Future post'] as $hidden) {
            self::assertStringNotContainsString($hidden, $list, "{$hidden} must not be listed");
        }
        self::assertStringContainsString('A &lt;b&gt;short&lt;/b&gt; summary', $list);

        foreach ([$draft, $archived, $future] as $id) {
            self::assertSame(404, $this->code('GET', '/blog/' . $this->row($id)['slug']));
        }
        self::assertSame(404, $this->code('GET', '/blog/no-such-article'));

        $res = $this->send('GET', '/blog/' . $this->row($live)['slug']);
        $page = $res->getBody();
        self::assertSame(200, $res->getStatus());
        self::assertStringContainsString('public, max-age=', (string) $res->getHeader('Cache-Control'));
        self::assertStringContainsString('Hello &lt;script&gt;alert(1)&lt;/script&gt; world.', $page);
        self::assertStringNotContainsString('<script>alert(1)', $page);
        self::assertStringContainsString('<a href="/overseas-jobs">jobs</a>', $page);
        self::assertStringContainsString('"@type":"BlogPosting"', $page);
        self::assertStringContainsString('rel="canonical" href="' . rtrim((string) config('app.url'), '/') . '/blog/' . $this->row($live)['slug'] . '"', $page);
        self::assertStringContainsString('BL Author', $page);
    }

    public function test_the_sitemap_lists_published_posts_and_nothing_else(): void
    {
        $actor = $this->model($this->user('manager'));
        $live = $this->draft($actor, ['title' => 'BLT Sitemap live']);
        $draft = $this->draft($actor, ['title' => 'BLT Sitemap draft']);
        $this->service()->publish($live, $actor);

        $this->actAs(null);
        $xml = $this->send('GET', '/sitemap.xml')->getBody();

        self::assertStringContainsString('/blog</loc>', $xml);
        self::assertStringContainsString('/blog/' . $this->row($live)['slug'] . '</loc>', $xml);
        self::assertStringNotContainsString('/blog/' . $this->row($draft)['slug'] . '<', $xml);
    }

    public function test_the_blog_is_reachable_from_the_footer_and_an_empty_blog_says_so(): void
    {
        $this->actAs(null);
        $before = (int) $this->db->selectValue("SELECT COUNT(*) FROM blog_posts WHERE status = 'published'");
        $page = $this->send('GET', '/blog')->getBody();

        self::assertStringContainsString('href="/blog"', $this->send('GET', '/about')->getBody());
        if ($before === 0) {
            self::assertStringContainsString('Nothing published yet', $page);
        }
        self::assertSame(200, $this->code('GET', '/blog?page=999'), 'a page past the end is an empty list, not an error');
    }
}
