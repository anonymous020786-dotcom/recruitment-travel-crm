<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Kernel;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\Authorize;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\VerifyCsrf;
use App\Http\Route;
use App\Http\Router;
use Tests\Support\DbTestCase;

/**
 * Access-control audit of the whole route table (Phase 12). Every route must be a conscious decision:
 * anything reachable without a session, or without a permission gate, has to be listed here with the reason —
 * so a new route that forgets `auth`, `can:`, CSRF or a throttle fails the build instead of shipping.
 */
final class RouteAuditTest extends DbTestCase
{
    /** Reachable without signing in. */
    private const ANONYMOUS = [
        'GET /', 'GET /about', 'GET /robots.txt', 'GET /sitemap.xml', 'GET /contact', 'POST /contact',
        'GET /overseas-jobs', 'GET /overseas-jobs/{slug}', 'GET /overseas-jobs/{slug}/apply', 'POST /overseas-jobs/{slug}/apply',
        'GET /travel-packages', 'GET /travel-packages/{slug}', 'GET /travel-packages/{slug}/enquire', 'POST /travel-packages/{slug}/enquire',
        'GET /health', 'GET /api/ping',
        'GET /login', 'POST /login', 'POST /login/passkey/options', 'POST /login/passkey',
        'GET /forgot-password', 'POST /forgot-password', 'GET /reset-password/{token}', 'POST /reset-password',
        'GET /two-factor', 'POST /two-factor', 'POST /two-factor/email', 'POST /two-factor/passkey/options', 'POST /two-factor/passkey',
    ];

    /** Signed in but with no `can:` gate — what enforces access instead. */
    private const NO_PERMISSION_GATE = [
        'POST /logout'                          => 'every signed-in user may sign out',
        'GET /dashboard'                        => 'widgets are filtered by the viewer\'s permissions and branch scope',
        'GET /notifications'                    => 'own notifications only',
        'POST /notifications/read-all'          => 'own notifications only',
        'GET /notifications/{id}/open'          => 'own notification only; the target screen enforces its own permission and branch scope',
        'GET /confirm-password'                 => 'own-account step-up',
        'POST /confirm-password'                => 'own-account step-up',
        'GET /account/profile'                  => 'own account only',
        'PUT /account/profile'                  => 'own account only',
        'GET /account/security'                 => 'own account only, behind step-up',
        'POST /account/password'                => 'own account, current password required',
        'GET /account/two-factor'               => 'own account only',
        'POST /account/two-factor'              => 'own account only',
        'POST /account/two-factor/disable'      => 'own account, behind step-up',
        'GET /account/recovery-codes'           => 'own account only',
        'POST /account/recovery-codes'          => 'own account, behind step-up',
        'GET /account/passkeys'                 => 'own account, behind step-up',
        'POST /account/passkeys/options'        => 'own account only',
        'POST /account/passkeys'                => 'own account only',
        'POST /account/passkeys/{id}/rename'    => 'own passkey, behind step-up',
        'POST /account/passkeys/{id}/delete'    => 'own passkey, behind step-up',
        'POST /account/passkeys/second-factor'  => 'own account, behind step-up',
        'POST /account/devices/revoke'          => 'own trusted devices only',
        'POST /account/sessions/revoke'         => 'own sessions only',
        'POST /account/sessions/revoke-all'     => 'own sessions, behind step-up',
        'POST /candidates/{candidate}/documents/{document}/review' => 'DocumentService requires documents.verify OR documents.reject (either one)',
    ];

    /** Signed-in writes exempt from the per-user write throttle. */
    private const UNTHROTTLED_WRITES = ['POST /logout'];

    /** @var list<Route> */
    private array $routes;
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($router);
        (require TEST_ROOT . '/routes/api.php')($router);
        $this->routes = $router->routes();
        $this->kernel = $this->app->get(Kernel::class);
    }

    private function label(Route $r, string $method): string
    {
        return "{$method} {$r->uri}";
    }

    /** @return list<string> the route's HTTP verbs, HEAD folded into GET */
    private function verbs(Route $r): array
    {
        return array_values(array_diff($r->methods, ['HEAD']));
    }

    /** @return list<string> expanded middleware class strings */
    private function stack(Route $r): array
    {
        return $this->kernel->expand($r->middleware);
    }

    private function has(array $stack, string $class): bool
    {
        foreach ($stack as $m) {
            if ($m === $class || str_starts_with($m, $class . ':')) {
                return true;
            }
        }

        return false;
    }

    private static function isWrite(Route $r): bool
    {
        return array_diff($r->methods, ['GET', 'HEAD']) !== [];
    }

    public function test_the_route_table_is_loaded(): void
    {
        self::assertGreaterThan(150, count($this->routes));
    }

    public function test_only_the_listed_routes_are_reachable_without_signing_in(): void
    {
        $anonymous = [];
        foreach ($this->routes as $r) {
            if (!$this->has($this->stack($r), Authenticate::class)) {
                foreach ($this->verbs($r) as $m) {
                    $anonymous[] = $this->label($r, $m);
                }
            }
        }

        self::assertSame([], array_values(array_diff($anonymous, self::ANONYMOUS)), 'route(s) reachable without authentication that are not on the audited list');
        self::assertSame([], array_values(array_diff(self::ANONYMOUS, $anonymous)), 'audited-anonymous route(s) that no longer exist or are now protected — update the list');
    }

    public function test_every_signed_in_route_has_a_permission_gate_or_a_reason_not_to(): void
    {
        $ungated = [];
        foreach ($this->routes as $r) {
            $stack = $this->stack($r);
            if ($this->has($stack, Authenticate::class) && !$this->has($stack, Authorize::class)) {
                foreach ($this->verbs($r) as $m) {
                    $ungated[] = $this->label($r, $m);
                }
            }
        }

        self::assertSame([], array_values(array_diff($ungated, array_keys(self::NO_PERMISSION_GATE))), 'signed-in route(s) with no can: gate and no recorded reason');
        self::assertSame([], array_values(array_diff(array_keys(self::NO_PERMISSION_GATE), $ungated)), 'listed exemption(s) that are now gated or gone — update the list');
    }

    public function test_every_state_changing_route_verifies_csrf(): void
    {
        $missing = [];
        foreach ($this->routes as $r) {
            if (self::isWrite($r) && !$this->has($this->stack($r), VerifyCsrf::class)) {
                $missing[] = $this->label($r, implode('|', $this->verbs($r)));
            }
        }

        self::assertSame([], $missing, 'write route(s) without CSRF verification');
    }

    public function test_no_state_change_hides_behind_a_get(): void
    {
        $formsOnly = ['/leads/{lead}/merge']; // GET renders the confirmation form; the POST does the merge
        $suspicious = [];
        foreach ($this->routes as $r) {
            if (in_array('GET', $r->methods, true) && !in_array($r->uri, $formsOnly, true) && preg_match('#/(delete|destroy|remove|revoke|approve|reject|cancel|reverse|void|issue|pay|paid|complete|publish|logout|convert|merge|assign)(/|$)#', $r->uri)) {
                $suspicious[] = $r->uri;
            }
        }

        self::assertSame([], $suspicious, 'GET route(s) whose path reads like an action');
    }

    public function test_every_signed_in_write_is_rate_limited(): void
    {
        $unthrottled = [];
        foreach ($this->routes as $r) {
            $stack = $this->stack($r);
            if (self::isWrite($r) && $this->has($stack, Authenticate::class) && !$this->has($stack, RateLimit::class)) {
                foreach ($this->verbs($r) as $m) {
                    $unthrottled[] = $this->label($r, $m);
                }
            }
        }

        self::assertSame([], array_values(array_diff($unthrottled, self::UNTHROTTLED_WRITES)), 'signed-in write route(s) without a throttle');
    }

    public function test_every_gate_names_a_permission_that_exists(): void
    {
        $catalogue = [];
        foreach ((array) $this->app->config()->get('permissions.catalogue', []) as $module => $actions) {
            foreach (array_keys($actions) as $action) {
                $catalogue["{$module}.{$action}"] = true;
            }
        }
        self::assertNotEmpty($catalogue);

        $unknown = [];
        foreach ($this->routes as $r) {
            foreach ($r->middleware as $m) {
                if (!str_starts_with($m, 'can:')) {
                    continue;
                }
                $ability = explode(',', substr($m, 4))[0];
                if (str_contains($ability, '.') && !isset($catalogue[$ability])) {
                    $unknown[] = $this->label($r, implode('|', $this->verbs($r))) . " → {$ability}";
                }
            }
        }

        self::assertSame([], $unknown, 'can: gate(s) naming a permission that is not in the catalogue (they would deny everyone but super admin)');
    }

    public function test_every_route_points_at_a_real_public_controller_method(): void
    {
        $broken = [];
        foreach ($this->routes as $r) {
            if (!is_array($r->handler)) {
                continue;
            }
            [$class, $method] = $r->handler;
            if (!class_exists($class) || !method_exists($class, $method) || !(new \ReflectionMethod($class, $method))->isPublic()) {
                $broken[] = $this->label($r, implode('|', $this->verbs($r))) . " → {$class}::{$method}";
            }
        }

        self::assertSame([], $broken);
    }

    /** Record-level access: a URL id must be resolved through the viewer's branch scope, or be an audited exception. */
    public function test_every_public_id_lookup_is_branch_scoped_unless_audited(): void
    {
        $exceptions = [
            'ExportRepository'      => 'the controller checks requested_by === the current user (404 otherwise)',
            'TourPackageRepository' => 'packages are an organisation-wide catalogue with no branch',
        ];
        $unscoped = [];

        foreach (glob(TEST_ROOT . '/app/Repositories/*Repository.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = "App\\Repositories\\{$short}";
            if (!class_exists($class) || !method_exists($class, 'findByPublicId')) {
                continue;
            }
            $takesScope = false;
            foreach ((new \ReflectionMethod($class, 'findByPublicId'))->getParameters() as $p) {
                $takesScope = $takesScope || ($p->getType()?->getName() === \App\Auth\BranchScope::class);
            }
            if (!$takesScope && !isset($exceptions[$short])) {
                $unscoped[] = $short;
            }
        }

        self::assertSame([], $unscoped, 'findByPublicId() without a BranchScope parameter — resolve ids through the viewer\'s scope or add an audited exception');
    }

    public function test_no_route_is_registered_twice_or_shadowed_by_an_earlier_wildcard(): void
    {
        $seen = [];
        $problems = [];

        foreach ($this->routes as $i => $r) {
            foreach ($this->verbs($r) as $m) {
                $key = $this->label($r, $m);
                if (isset($seen[$key])) {
                    $problems[] = "duplicate {$key}";
                }
                $seen[$key] = true;
            }

            if (str_contains($r->uri, '{')) {
                continue;
            }
            for ($j = 0; $j < $i; $j++) {
                $earlier = $this->routes[$j];
                if (!str_contains($earlier->uri, '{')) {
                    continue;
                }
                foreach ($this->verbs($r) as $m) {
                    if ($earlier->match($m, $r->uri) !== null) {
                        $problems[] = "{$m} {$r->uri} is shadowed by {$earlier->uri}";
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }
}
