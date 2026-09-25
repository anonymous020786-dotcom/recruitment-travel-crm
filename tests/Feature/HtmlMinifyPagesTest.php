<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Http\Middleware\MinifyHtml;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\UserRepository;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;
use Tests\Support\HtmlSignature;

/**
 * The HTML minifier on the application's own pages: every page must render identically (same elements, attributes and
 * text) before and after, and pages must actually get smaller. Also the middleware's on/off rules.
 */
final class HtmlMinifyPagesTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private string $token = '';
    private int $branch;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'HM branch', 'code' => 'HMX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['HMX-%']);
    }

    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => time()] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    private function superAdmin(): int
    {
        $role = (int) $this->db->selectValue("SELECT id FROM roles WHERE name = 'super_admin'");
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => 'HM admin', 'email' => 'hm_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $role, 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function get(string $uri): Response
    {
        return $this->router->dispatch(new Request([], [], ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    private function render(string $uri, bool $minify): string
    {
        $config = $this->app->config();
        $config->set('app.debug', false);
        $config->set('app.minify_html', $minify);
        $res = $this->get($uri);
        self::assertSame(200, $res->getStatus(), $uri);

        return $res->getBody();
    }

    /** @return list<string> */
    private static function publicPages(): array
    {
        return ['/', '/about', '/contact', '/overseas-jobs', '/travel-packages', '/blog', '/login'];
    }

    /** @return list<string> */
    private static function staffPages(): array
    {
        return ['/dashboard', '/leads', '/candidates', '/employers', '/jobs', '/applications', '/invoices', '/reports', '/reports/recruitment-funnel',
            '/admin/users', '/admin/roles', '/admin/roles/counselor', '/admin/settings', '/admin/audit', '/admin/blog', '/admin/blog/create', '/admin/branches', '/admin/branches/create', '/admin/lead-sources', '/tours/packages', '/tasks', '/tasks/create', '/search?q=a'];
    }

    public function test_public_pages_render_identically_after_minifying_and_get_smaller(): void
    {
        $this->actAs(null);
        foreach (self::publicPages() as $uri) {
            $raw = $this->render($uri, false);
            $min = $this->render($uri, true);

            self::assertSame(HtmlSignature::of($raw), HtmlSignature::of($min), "{$uri} changed structure or text when minified");
            self::assertLessThan(strlen($raw), strlen($min), "{$uri} did not shrink");
            self::assertSame(1, preg_match('/^<!doctype html>/i', $min), "{$uri} lost its doctype");
        }
    }

    public function test_staff_pages_render_identically_after_minifying_and_get_smaller(): void
    {
        $this->actAs($this->superAdmin());
        $saved = 0;
        $total = 0;
        foreach (self::staffPages() as $uri) {
            $raw = $this->render($uri, false);
            $min = $this->render($uri, true);

            self::assertSame(HtmlSignature::of($raw), HtmlSignature::of($min), "{$uri} changed structure or text when minified");
            self::assertLessThan(strlen($raw), strlen($min), "{$uri} did not shrink");
            $saved += strlen($raw) - strlen($min);
            $total += strlen($raw);
        }
        self::assertGreaterThan(0.08, $saved / $total, 'minifying should save a meaningful share of the markup overall');
    }

    // ---- the middleware's rules ------------------------------------------------------------------------------------------

    private function through(Response $response, bool $minify, bool $debug): Response
    {
        $config = $this->app->config();
        $config->set('app.minify_html', $minify);
        $config->set('app.debug', $debug);

        return (new MinifyHtml($this->app))->handle(new Request([], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], ''), static fn (): Response => $response);
    }

    public function test_the_middleware_only_touches_complete_html_when_switched_on(): void
    {
        $page = "<!doctype html>\n<html>\n  <body>\n    <p>  spaced   out  </p>\n    <!-- gone -->\n  </body>\n</html>\n" . str_repeat(' ', 80);
        $html = static fn (): Response => Response::make($page, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        self::assertStringNotContainsString('gone', $this->through($html(), true, false)->getBody());
        self::assertSame($page, $this->through($html(), false, false)->getBody(), 'HTML_MINIFY=0 turns it off');
        self::assertSame($page, $this->through($html(), true, true)->getBody(), 'APP_DEBUG keeps "view source" readable');

        $json = Response::make($page, 200, ['Content-Type' => 'application/json']);
        self::assertSame($page, $this->through($json, true, false)->getBody());
        $csv = Response::make($page, 200, ['Content-Type' => 'text/csv']);
        self::assertSame($page, $this->through($csv, true, false)->getBody());
        $download = Response::make($page, 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="x.html"']);
        self::assertSame($page, $this->through($download, true, false)->getBody(), 'a downloaded file is delivered exactly as stored');
        $empty = Response::make('', 204, ['Content-Type' => 'text/html']);
        self::assertSame('', $this->through($empty, true, false)->getBody());
    }

    public function test_the_web_groups_run_the_minifier_outermost(): void
    {
        $kernel = new \App\Http\Kernel();

        self::assertSame(MinifyHtml::class, $kernel->groups['web.public'][0]);
        self::assertSame(MinifyHtml::class, $kernel->groups['web.crm'][0]);
    }
}
