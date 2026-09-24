<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Public\SeoController;
use App\Http\Kernel;
use App\Http\Middleware\Authenticate;
use App\Http\Router;
use App\Support\Application;
use App\Support\Config;
use App\Support\HtmlAudit;
use App\View\Assets;
use App\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/**
 * The real public pages and the sign-in page, rendered from the real views, must pass the accessibility / SEO audit
 * (no errors). The signed-in screens need a session and data, so they are covered by `scripts/html-audit.php`.
 */
final class PageAuditTest extends TestCase
{
    private View $view;
    private Application $app;

    protected function setUp(): void
    {
        $app = TestApp::make(['app.url' => 'https://crm.acmetravel.in', 'app.name' => 'Acme Travel', 'seo.organization_name' => 'Acme Travel Pvt Ltd']);
        $app->instance(Assets::class, new Assets(TEST_ROOT . '/public'));
        $this->app = $app;
        $this->view = new View(TEST_ROOT . '/resources/views');
        $app->instance(View::class, $this->view);
        $app->instance(Config::class, $app->config());
        (require TEST_ROOT . '/bootstrap/services.php')($app);   // the real bindings (integrations, Turnstile, …)
        $app->instance(View::class, $this->view);
    }

    /** @return list<array{rule:string,severity:string,message:string,snippet:string}> */
    private function errors(string $html, string $kind, array $headers = []): array
    {
        return array_values(array_filter(
            (new HtmlAudit())->check($html, $kind, $headers),
            static fn (array $f): bool => $f['severity'] === HtmlAudit::ERROR,
        ));
    }

    /** @return array<string,array{0:string}> */
    public static function publicPages(): array
    {
        return ['home' => ['public.home'], 'about' => ['public.about'], 'contact' => ['public.contact']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicPages')]
    public function test_public_pages_have_no_accessibility_or_seo_errors(string $template): void
    {
        $html = $this->view->render($template, ['csrfToken' => 'tok', 'errors' => [], 'old' => []]);

        self::assertSame([], $this->errors($html, 'public'), $template);
    }

    public function test_public_layout_has_a_skip_link_structured_data_and_labelled_navigation(): void
    {
        $html = $this->view->render('public.home', []);

        self::assertStringContainsString('href="#main"', $html);
        self::assertStringContainsString('<main id="main"', $html);
        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('"@type":"Organization"', $html);
        self::assertStringContainsString('property="og:url"', $html);
        self::assertStringContainsString('aria-label="Primary"', $html);
        self::assertStringContainsString('aria-label="Footer"', $html);
        self::assertStringNotContainsString('</script><script', $html, 'JSON-LD is HEX_TAG-escaped');
    }

    public function test_the_sign_in_page_has_no_errors(): void
    {
        $html = $this->view->render('auth.login', ['csrfToken' => 'tok', 'errors' => [], 'old' => [], 'passkeysEnabled' => false]);

        self::assertSame([], $this->errors($html, 'app', ['x-robots-tag' => 'noindex']));
    }

    // ---- no dead links ---------------------------------------------------------------------------------

    /** @return list<\App\Http\Route> GET routes an anonymous visitor can open */
    private function anonymousGetRoutes(): array
    {
        $router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($router);
        $kernel = $this->app->get(Kernel::class);
        $out = [];
        foreach ($router->routes() as $r) {
            $auth = false;
            foreach ($kernel->expand($r->middleware) as $m) {
                $auth = $auth || $m === Authenticate::class;
            }
            if (!$auth && in_array('GET', $r->methods, true)) {
                $out[] = $r;
            }
        }

        return $out;
    }

    private function opensAnonymously(string $path): bool
    {
        $path = strtok($path, '?#') ?: '/';
        foreach ($this->anonymousGetRoutes() as $r) {
            if ($r->match('GET', $path) !== null) {
                return true;
            }
        }

        return false;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicPages')]
    public function test_every_internal_link_on_a_public_page_opens_for_an_anonymous_visitor(string $template): void
    {
        $html = $this->view->render($template, ['csrfToken' => 'tok', 'errors' => [], 'old' => []]);
        $dead = [];
        preg_match_all('/(?:href|action)="(\/[^"]*)"/', $html, $m);
        foreach (array_unique($m[1]) as $link) {
            if (str_starts_with($link, '//') || str_starts_with($link, '/assets/') || str_starts_with($link, '/build/')) {
                continue;
            }
            if (!$this->opensAnonymously($link)) {
                $dead[] = $link;
            }
        }

        self::assertSame([], $dead, "{$template} links to pages a visitor cannot open (they redirect to sign-in or 404)");
    }

    public function test_the_sitemap_lists_only_public_pages(): void
    {
        $xml = $this->app->get(SeoController::class)->sitemap()->getBody();
        preg_match_all('#<loc>https://crm\.acmetravel\.in([^<]*)</loc>#', $xml, $m);

        self::assertNotEmpty($m[1]);
        self::assertContains('/', $m[1]);
        foreach ($m[1] as $path) {
            self::assertTrue($this->opensAnonymously($path === '' ? '/' : $path), "sitemap lists {$path}, which a visitor cannot open");
        }
    }
}
