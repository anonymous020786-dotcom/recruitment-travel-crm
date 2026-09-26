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
use App\Repositories\CmsSiteRepository;
use App\Repositories\UserRepository;
use App\Services\CmsPageService;
use App\Services\CmsSiteService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Pages → Redirects, Snippets and Menus, and what they do on the public site. */
final class CmsSiteToolsTest extends DbTestCase
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
    private ?User $publisher = null;
    private ?User $writer = null;
    /** @var array<string,list<array<string,mixed>>> */
    private array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        foreach (['cms_redirects', 'cms_snippets', 'cms_menu_items'] as $t) {
            $this->snapshot[$t] = $this->db->select("SELECT * FROM {$t}");
            $this->db->affectingStatement("DELETE FROM {$t}");
        }
        $this->db->affectingStatement("DELETE FROM cms_pages WHERE path LIKE 'tsite-%'");
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'Site branch', 'code' => 'STX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
        $this->app->instance(CmsSiteRepository::class, new CmsSiteRepository($this->db));   // fresh per-request caches
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $t => $rows) {
            $this->db->affectingStatement("DELETE FROM {$t}");
            foreach ($rows as $row) {
                $this->db->insertRow($t, $row);
            }
        }
        $this->db->affectingStatement("DELETE FROM cms_pages WHERE path LIKE 'tsite-%'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'cms'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['STX-%']);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "TSITE {$role}", 'email' => 'tsite_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function publisher(): User
    {
        return $this->publisher ??= $this->app->get(UserRepository::class)->findById($this->user('admin'));
    }

    private function writer(): User
    {
        return $this->writer ??= $this->app->get(UserRepository::class)->findById($this->user('manager'));
    }

    private function svc(): CmsSiteService
    {
        return $this->app->get(CmsSiteService::class);
    }

    private function fresh(): void
    {
        $this->app->instance(CmsSiteRepository::class, new CmsSiteRepository($this->db));
    }

    /** @param array<string,mixed> $over */
    private function redirect(string $from, ?string $to, array $over = []): int
    {
        return $this->svc()->saveRedirect($over + ['from' => $from, 'to' => $to ?? '', 'code' => $to === null ? '410' : '301', 'keep_query' => '1', 'is_active' => '1'], $this->publisher());
    }

    private function errorsOf(callable $do, string $label = ''): array
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail("a validation error was expected {$label}");
    }

    private function actAs(int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => time(), '_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    /** @param array<string,mixed> $post */
    private function send(string $method, string $uri, array $post = []): Response
    {
        if ($post !== [] && !isset($post['_token']) && $this->token !== '') {
            $post['_token'] = $this->token;
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->router->dispatch(new Request($query, $post, $this->sid !== '' ? ['crm_session' => $this->sid] : [], [], [
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

    private function livePage(string $path, string $body): string
    {
        $pages = $this->app->get(CmsPageService::class);
        $id = $pages->create(['title' => 'TSITE ' . $path, 'path' => $path, 'body' => $body], $this->publisher());
        $pages->publish($id, $this->publisher());

        return $id;
    }

    // ---- redirects: rules -------------------------------------------------------------------------------------------

    public function test_old_addresses_are_normalised(): void
    {
        $svc = $this->svc();
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);
        self::assertSame('/old-page.html', $svc->normalizeFrom('/Old-Page.html/'));
        self::assertSame('/old', $svc->normalizeFrom('old?utm=1#top'));
        self::assertSame('/a/b', $svc->normalizeFrom('  /a/b/  '));
        self::assertNull($svc->normalizeFrom('/caf%C3%A9'), 'old addresses are plain ASCII paths');
        if (is_string($own)) {
            self::assertSame('/x', $svc->normalizeFrom('https://' . $own . '/x'));
        }
        foreach (['', '/', 'https://someone-else.example/x', '/a//b', '/a/../b', '/../etc', "/a\nb", '/a b', '/<script>', str_repeat('/a', 200)] as $bad) {
            self::assertNull($svc->normalizeFrom($bad), var_export($bad, true));
        }
    }

    public function test_a_redirect_cannot_hide_a_route_or_a_page_and_targets_are_checked(): void
    {
        $this->livePage('tsite-live', 'Hello.');
        $cases = [
            ['from' => '/about', 'to' => '/x', 'field' => 'from'],
            ['from' => '/overseas-jobs/some-old-job', 'to' => '/x', 'field' => 'from'],   // a route pattern answers there
            ['from' => '/admin/cms', 'to' => '/x', 'field' => 'from'],
            ['from' => '/tsite-live', 'to' => '/x', 'field' => 'from'],
            ['from' => '/tsite-old', 'to' => 'javascript:alert(1)', 'field' => 'to'],
            ['from' => '/tsite-old', 'to' => 'http://insecure.example/', 'field' => 'to'],
            ['from' => '/tsite-old', 'to' => '//evil.example/', 'field' => 'to'],
            ['from' => '/tsite-old', 'to' => '', 'field' => 'to'],
            ['from' => '/tsite-old', 'to' => '/tsite-old', 'field' => 'to'],
            ['from' => '/tsite-old', 'to' => '/x', 'code' => '303', 'field' => 'code'],
            ['from' => '/tsite-old', 'to' => '/x', 'note' => str_repeat('n', 201), 'field' => 'note'],
        ];
        foreach ($cases as $c) {
            $field = $c['field'];
            unset($c['field']);
            self::assertArrayHasKey($field, $this->errorsOf(fn () => $this->svc()->saveRedirect($c + ['code' => '301'], $this->publisher()), json_encode($c)));
        }
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_redirects'));

        $this->redirect('/tsite-old', '/tsite-new');
        self::assertArrayHasKey('from', $this->errorsOf(fn () => $this->redirect('/TSITE-OLD/', '/elsewhere')), 'duplicates are caught after normalising');
    }

    public function test_loops_and_chains_are_refused_and_older_redirects_are_repointed(): void
    {
        $this->redirect('/tsite-a', '/tsite-b');
        self::assertArrayHasKey('to', $this->errorsOf(fn () => $this->redirect('/tsite-b', '/tsite-a')), 'b→a would loop with a→b');
        self::assertArrayHasKey('to', $this->errorsOf(fn () => $this->redirect('/tsite-c', '/tsite-a')), 'c→a→b is a chain');

        $this->redirect('/tsite-b', '/tsite-final');   // a→b now continues to final…
        self::assertSame('/tsite-final', $this->db->selectValue("SELECT to_url FROM cms_redirects WHERE from_path = '/tsite-a'"), '…so a is re-pointed straight at final');
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'cms_redirect_added' AND new_values LIKE '%\"rechained\":1%'"));
    }

    public function test_redirects_can_be_edited_switched_off_and_removed(): void
    {
        $id = $this->redirect('/tsite-e', '/tsite-one', ['note' => 'first']);
        $this->svc()->saveRedirect(['from' => '/tsite-e', 'to' => '/tsite-two', 'code' => '302', 'note' => 'second'], $this->publisher(), $id);
        $row = $this->app->get(CmsSiteRepository::class)->redirect($id);
        self::assertSame('/tsite-two', $row['to_url']);
        self::assertSame(302, (int) $row['status_code']);
        self::assertSame(0, (int) $row['keep_query'], 'an unticked box is saved as off');

        self::assertTrue($this->svc()->toggleRedirect($id, $this->publisher()));
        self::assertSame(0, (int) $this->app->get(CmsSiteRepository::class)->redirect($id)['is_active']);
        self::assertTrue($this->svc()->deleteRedirect($id, $this->publisher()));
        self::assertFalse($this->svc()->deleteRedirect($id, $this->publisher()));
        self::assertFalse($this->svc()->toggleRedirect($id, $this->publisher()));
        foreach (['cms_redirect_added', 'cms_redirect_updated', 'cms_redirect_toggled', 'cms_redirect_removed'] as $a) {
            self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE module = 'cms' AND action = :a", ['a' => $a]), $a);
        }
    }

    public function test_import_saves_good_lines_and_reports_bad_ones(): void
    {
        $this->redirect('/tsite-exists', '/tsite-was');
        $r = $this->svc()->import("from,to,code\n/tsite-i1, /tsite-new1\n/tsite-i2;https://partner.example/page;302\n# a comment\n/tsite-gone,,410\n/about, /x\n/tsite-exists\t/tsite-now\nnot-valid-line", $this->publisher());
        self::assertSame(3, $r['added']);
        self::assertSame(1, $r['updated']);
        self::assertCount(2, $r['errors']);
        self::assertStringContainsString('/about', $r['errors'][0]);
        self::assertSame('/tsite-now', $this->db->selectValue("SELECT to_url FROM cms_redirects WHERE from_path = '/tsite-exists'"));
        self::assertSame(410, (int) $this->db->selectValue("SELECT status_code FROM cms_redirects WHERE from_path = '/tsite-gone'"));
        self::assertArrayHasKey('import', $this->errorsOf(fn () => $this->svc()->import("  \n# only comments\n", $this->publisher())));
        self::assertArrayHasKey('import', $this->errorsOf(fn () => $this->svc()->import(str_repeat("/a, /b\n", 501), $this->publisher())));
    }

    // ---- redirects on the public site -------------------------------------------------------------------------------

    public function test_the_public_site_follows_redirects_and_counts_them(): void
    {
        $perm = $this->redirect('/tsite-moved.html', '/tsite-here');
        $this->redirect('/tsite-temp', 'https://partner.example/offer', ['code' => '302', 'keep_query' => '']);
        $this->redirect('/tsite-removed', null);
        $off = $this->redirect('/tsite-off', '/x');
        $this->svc()->toggleRedirect($off, $this->publisher());

        $res = $this->send('GET', '/TSITE-Moved.html?utm_source=x&a=1');
        self::assertSame(301, $res->getStatus());
        self::assertSame('/tsite-here?utm_source=x&a=1', $res->getHeader('Location'), 'the query string travels along');
        self::assertStringContainsString('max-age=3600', (string) $res->getHeader('Cache-Control'));

        $tmp = $this->send('GET', '/tsite-temp?utm_source=x');
        self::assertSame(302, $tmp->getStatus());
        self::assertSame('https://partner.example/offer', $tmp->getHeader('Location'), 'external targets work; keep-query off drops the query');
        self::assertSame('no-store', $tmp->getHeader('Cache-Control'));

        $gone = $this->send('GET', '/tsite-removed');
        self::assertSame(410, $gone->getStatus());
        self::assertStringContainsString('has been removed', $gone->getBody());
        self::assertStringContainsString('noindex', $gone->getBody());

        self::assertSame(404, $this->code('GET', '/tsite-off'), 'a switched-off redirect does nothing');
        self::assertSame(404, $this->code('GET', '/tsite-unknown'));
        self::assertSame(404, $this->code('POST', '/tsite-moved.html'), 'only GET/HEAD reach the fallback');

        $row = $this->app->get(CmsSiteRepository::class)->redirect($perm);
        self::assertSame(1, (int) $row['hits']);
        self::assertNotNull($row['last_hit_at']);
    }

    // ---- snippets ---------------------------------------------------------------------------------------------------

    public function test_snippets_are_validated(): void
    {
        $svc = $this->svc();
        $p = $this->publisher();
        $cases = [
            'key' => ['key' => 'Bad Key', 'title' => 'x', 'body' => 'x'],
            'title' => ['key' => 'tsite-ok', 'title' => '', 'body' => 'x'],
            'body' => ['key' => 'tsite-ok', 'title' => 'x', 'body' => ''],
        ];
        foreach ($cases as $field => $input) {
            self::assertArrayHasKey($field, $this->errorsOf(fn () => $svc->saveSnippet($input, $p), $field));
        }
        self::assertArrayHasKey('body', $this->errorsOf(fn () => $svc->saveSnippet(['key' => 'tsite-n', 'title' => 'x', 'body' => 'a {{snippet:other}} b'], $p)), 'no nesting');
        self::assertArrayHasKey('body', $this->errorsOf(fn () => $svc->saveSnippet(['key' => 'tsite-n', 'title' => 'x', 'body' => str_repeat('a', 20001)], $p)));
        $svc->saveSnippet(['key' => 'tsite-cta', 'title' => 'CTA', 'body' => 'Call us', 'is_active' => '1'], $p);
        self::assertArrayHasKey('key', $this->errorsOf(fn () => $svc->saveSnippet(['key' => 'tsite-cta', 'title' => 'x', 'body' => 'x'], $p)), 'keys are unique');
        try {
            $svc->saveSnippet(['key' => 'tsite-w', 'title' => 'x', 'body' => 'x'], $this->writer());
            self::fail('a writer cannot change site-wide content');
        } catch (AuthorizationException) {
            self::assertNull($this->app->get(CmsSiteRepository::class)->snippet('tsite-w'));
        }
    }

    public function test_a_snippet_appears_on_every_page_that_uses_it_and_its_blocks_are_filled(): void
    {
        $svc = $this->svc();
        $svc->saveSnippet(['key' => 'tsite-cta', 'title' => 'CTA', 'body' => "## Ready to go abroad?\n\n**Talk to us** <b>today</b>.\n\n{{contact:Book a call}}", 'is_active' => '1'], $this->publisher());
        $this->livePage('tsite-one', "First page.\n\n{{snippet:tsite-cta}}");
        $this->livePage('tsite-two', "Second page.\n\n{{snippet:tsite-cta}}\n\n{{snippet:tsite-missing}}");

        foreach (['/tsite-one', '/tsite-two'] as $url) {
            $html = $this->send('GET', $url)->getBody();
            self::assertStringContainsString('Ready to go abroad?', $html, $url);
            self::assertStringContainsString('<strong>Talk to us</strong> &lt;b&gt;today&lt;/b&gt;', $html, $url);
            self::assertStringContainsString('Book a call', $html, 'placeholders inside a snippet are filled');
            self::assertStringNotContainsString('cms-sc', $html, 'a missing snippet leaves nothing behind');
        }

        // edit once, changes everywhere
        $svc->saveSnippet(['title' => 'CTA', 'body' => 'New wording', 'is_active' => '1'], $this->publisher(), 'tsite-cta');
        $this->fresh();
        self::assertStringContainsString('New wording', $this->send('GET', '/tsite-two')->getBody());

        // switched off → shows nothing
        $svc->saveSnippet(['title' => 'CTA', 'body' => 'New wording', 'is_active' => ''], $this->publisher(), 'tsite-cta');
        $this->fresh();
        self::assertStringNotContainsString('New wording', $this->send('GET', '/tsite-one')->getBody());

        // a nested snippet planted in the database directly still never renders (no loops)
        $this->db->affectingStatement("UPDATE cms_snippets SET is_active = 1, body_html = CONCAT(body_html, '<div class=\"cms-sc\" data-sc=\"snippet\" data-a=\"tsite-cta\"></div>') WHERE key_name = 'tsite-cta'");
        $this->fresh();
        $html = $this->send('GET', '/tsite-one')->getBody();
        self::assertSame(1, substr_count($html, 'New wording'));
    }

    public function test_a_snippet_in_use_cannot_be_deleted(): void
    {
        $this->svc()->saveSnippet(['key' => 'tsite-used', 'title' => 'Used', 'body' => 'x', 'is_active' => '1'], $this->publisher());
        $page = $this->livePage('tsite-user', "{{snippet:tsite-used}}");
        try {
            $this->svc()->deleteSnippet('tsite-used', $this->publisher());
            self::fail('in use');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('/tsite-user', $e->getMessage());
        }
        $this->app->get(CmsPageService::class)->trash($page, $this->publisher());
        $this->svc()->deleteSnippet('tsite-used', $this->publisher());
        self::assertNull($this->app->get(CmsSiteRepository::class)->snippet('tsite-used'));
    }

    // ---- menus ------------------------------------------------------------------------------------------------------

    public function test_menu_items_are_validated_capped_and_ordered(): void
    {
        $svc = $this->svc();
        $p = $this->publisher();
        foreach ([['menu' => 'sidebar', 'label' => 'x', 'url' => '/x', 'f' => 'menu'], ['menu' => 'header', 'label' => '', 'url' => '/x', 'f' => 'label'], ['menu' => 'header', 'label' => 'x', 'url' => 'javascript:alert(1)', 'f' => 'url'], ['menu' => 'header', 'label' => 'x', 'url' => '//evil.example', 'f' => 'url'], ['menu' => 'header', 'label' => 'x', 'url' => 'http://insecure.example', 'f' => 'url']] as $c) {
            $f = $c['f'];
            unset($c['f']);
            self::assertArrayHasKey($f, $this->errorsOf(fn () => $svc->saveMenuItem($c + ['is_active' => '1'], $p), json_encode($c)));
        }
        $ids = [];
        foreach (['Home' => '/', 'Visa' => '/visa-services', 'Mail' => 'mailto:hello@example.com', 'Call' => 'tel:+911234567890', 'Partner' => 'https://partner.example/'] as $label => $url) {
            $ids[] = $svc->saveMenuItem(['menu' => 'header', 'label' => $label, 'url' => $url, 'is_active' => '1'], $p);
        }
        $order = fn (): array => array_column($this->app->get(CmsSiteRepository::class)->menu('header'), 'label');
        self::assertSame(['Home', 'Visa', 'Mail', 'Call', 'Partner'], $order());
        self::assertTrue($svc->moveMenuItem($ids[1], -1, $p));
        self::assertSame(['Visa', 'Home', 'Mail', 'Call', 'Partner'], $order());
        self::assertFalse($svc->moveMenuItem($ids[1], -1, $p), 'already first');
        self::assertTrue($svc->moveMenuItem($ids[4], -1, $p));
        self::assertSame(['Visa', 'Home', 'Mail', 'Partner', 'Call'], $order());

        for ($i = count($ids); $i < CmsSiteService::MAX_MENU_ITEMS; $i++) {
            $svc->saveMenuItem(['menu' => 'header', 'label' => "L{$i}", 'url' => "/l{$i}", 'is_active' => '1'], $p);
        }
        self::assertArrayHasKey('menu', $this->errorsOf(fn () => $svc->saveMenuItem(['menu' => 'header', 'label' => 'one more', 'url' => '/x', 'is_active' => '1'], $p)));
        self::assertTrue($svc->deleteMenuItem($ids[0], $p));
        self::assertFalse($svc->deleteMenuItem($ids[0], $p));
    }

    public function test_the_public_layout_uses_the_menus_and_falls_back_to_the_built_in_links(): void
    {
        $page = $this->livePage('tsite-menu', 'Menu test.');
        unset($page);
        $body = $this->send('GET', '/tsite-menu')->getBody();
        self::assertStringContainsString('href="/overseas-jobs"', $body, 'built-in links while no menu is set');

        $p = $this->publisher();
        $this->svc()->saveMenuItem(['menu' => 'header', 'label' => 'Our visa help', 'url' => '/tsite-menu', 'is_active' => '1'], $p);
        $this->svc()->saveMenuItem(['menu' => 'header', 'label' => 'Hidden one', 'url' => '/hidden', 'is_active' => ''], $p);
        $this->svc()->saveMenuItem(['menu' => 'footer', 'label' => 'Partner <site>', 'url' => 'https://partner.example/', 'is_active' => '1', 'new_tab' => '1'], $p);
        $this->fresh();
        $html = $this->send('GET', '/tsite-menu')->getBody();
        [$header, $rest] = explode('</header>', $html, 2);
        self::assertStringContainsString('Our visa help', $header);
        self::assertStringNotContainsString('Hidden one', $html);
        self::assertStringNotContainsString('>Jobs<', $header, 'the menu replaces the built-in header links');
        self::assertStringContainsString('Partner &lt;site&gt;', $rest);
        self::assertStringContainsString('href="https://partner.example/" target="_blank" rel="noopener"', $rest);
        self::assertStringNotContainsString('>Blog</a>', $rest, 'the footer menu replaces the built-in footer links');
    }

    // ---- the screens -------------------------------------------------------------------------------------------------

    public function test_writers_see_the_tools_but_only_publishers_change_them(): void
    {
        $this->actAs($this->writer()->id);
        foreach (['/admin/cms/redirects', '/admin/cms/snippets', '/admin/cms/menus'] as $u) {
            self::assertSame(200, $this->code('GET', $u), $u);
        }
        self::assertSame(403, $this->code('POST', '/admin/cms/redirects', ['from' => '/tsite-x', 'to' => '/y', 'code' => '301']));
        self::assertSame(403, $this->code('POST', '/admin/cms/menus', ['menu' => 'header', 'label' => 'x', 'url' => '/x']));
        self::assertSame(403, $this->code('GET', '/admin/cms/snippets/create'));
        self::assertStringNotContainsString('Add a redirect', $this->send('GET', '/admin/cms/redirects')->getBody());

        $this->actAs($this->user('read_only'));
        self::assertSame(403, $this->code('GET', '/admin/cms/redirects'));
    }

    public function test_the_screens_manage_everything(): void
    {
        $this->actAs($this->publisher()->id);
        $this->send('POST', '/admin/cms/redirects', ['from' => '/tsite-s1', 'to' => '/tsite-s2', 'code' => '301', 'keep_query' => '1', 'is_active' => '1']);
        $id = (int) $this->db->selectValue("SELECT id FROM cms_redirects WHERE from_path = '/tsite-s1'");
        self::assertGreaterThan(0, $id);
        $list = $this->send('GET', '/admin/cms/redirects')->getBody();
        self::assertStringContainsString('/tsite-s1', $list);
        self::assertStringContainsString('value="/tsite-s1"', $this->send('GET', "/admin/cms/redirects?edit={$id}")->getBody());
        $this->send('PUT', "/admin/cms/redirects/{$id}", ['_method' => 'PUT', 'from' => '/tsite-s1', 'to' => '/tsite-s3', 'code' => '308']);
        self::assertSame(308, (int) $this->db->selectValue('SELECT status_code FROM cms_redirects WHERE id = :i', ['i' => $id]));
        $bad = $this->send('POST', '/admin/cms/redirects', ['from' => '/about', 'to' => '/x', 'code' => '301']);
        self::assertSame('/admin/cms/redirects', $bad->getHeader('Location'));
        $this->send('POST', '/admin/cms/redirects/import', ['import' => "/tsite-m1, /a\n/about, /b"]);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_redirects WHERE from_path = '/tsite-m1'"));
        self::assertStringContainsString('skipped', $this->send('GET', '/admin/cms/redirects')->getBody());
        $this->send('POST', "/admin/cms/redirects/{$id}/toggle", ['x' => '1']);
        $this->send('POST', "/admin/cms/redirects/{$id}/delete", ['x' => '1']);
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_redirects WHERE id = :i', ['i' => $id]));
        self::assertSame(404, $this->code('POST', '/admin/cms/redirects/abc/toggle', ['x' => '1']));
        self::assertSame(404, $this->code('POST', '/admin/cms/redirects/999999/toggle', ['x' => '1']));

        $res = $this->send('POST', '/admin/cms/snippets', ['key' => 'tsite-s', 'title' => 'S', 'body' => 'Snippet body', 'is_active' => '1']);
        self::assertSame('/admin/cms/snippets/tsite-s/edit', $res->getHeader('Location'));
        self::assertStringContainsString('{{snippet:tsite-s}}', $this->send('GET', '/admin/cms/snippets')->getBody());
        $this->send('PUT', '/admin/cms/snippets/tsite-s', ['_method' => 'PUT', 'title' => 'S2', 'body' => 'Changed', 'is_active' => '1']);
        self::assertSame('S2', $this->db->selectValue("SELECT title FROM cms_snippets WHERE key_name = 'tsite-s'"));
        self::assertSame(404, $this->code('GET', '/admin/cms/snippets/nope/edit'));
        $this->send('POST', '/admin/cms/snippets/tsite-s/delete', ['x' => '1']);
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_snippets WHERE key_name = 'tsite-s'"));

        $this->send('POST', '/admin/cms/menus', ['menu' => 'footer', 'label' => 'Visa', 'url' => '/visa', 'is_active' => '1']);
        $this->send('POST', '/admin/cms/menus', ['menu' => 'footer', 'label' => 'Jobs', 'url' => '/overseas-jobs', 'is_active' => '1']);
        $ids = array_map('intval', array_column($this->db->select("SELECT id FROM cms_menu_items WHERE menu = 'footer' ORDER BY sort_order"), 'id'));
        $this->send('POST', "/admin/cms/menus/{$ids[1]}/move", ['dir' => 'up']);
        self::assertSame(['Jobs', 'Visa'], array_column($this->db->select("SELECT label FROM cms_menu_items WHERE menu = 'footer' ORDER BY sort_order"), 'label'));
        $this->send('PUT', "/admin/cms/menus/{$ids[0]}", ['_method' => 'PUT', 'label' => 'Visa help', 'url' => '/visa', 'is_active' => '1']);
        self::assertSame('Visa help', $this->db->selectValue('SELECT label FROM cms_menu_items WHERE id = :i', ['i' => $ids[0]]));
        $page = $this->send('GET', '/admin/cms/menus')->getBody();
        self::assertStringContainsString('Visa help', $page);
        $this->send('POST', "/admin/cms/menus/{$ids[0]}/delete", ['x' => '1']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_menu_items WHERE menu = 'footer'"));
    }
}
