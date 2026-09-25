<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\TwoFactor;
use App\Exceptions\AuthorizationException;
use App\Exceptions\Handler;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\DbTestCase;
use Tests\Support\TestApp;

/**
 * Deployment-facing hardening (Phase 12): the Apache files that keep application code, secrets and uploads out of
 * reach, safe defaults in .env.example, and the headers on error pages. These are text/behaviour checks on files that
 * only matter on the real host, so they fail here instead of in production.
 */
final class WebServerConfigTest extends TestCase
{
    private static function read(string $relative): string
    {
        $path = TEST_ROOT . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // ---- Apache: fallback (docroot cannot be moved) ---------------------------

    /** @return list<string> */
    private static function denyList(): array
    {
        preg_match('#RewriteRule \^\(([^)]+)\)\(/\|\$\) - \[F,L\]#', self::read('.htaccess'), $m);
        self::assertNotEmpty($m, 'the deny RewriteRule in .htaccess was not found or changed shape');

        return array_map(static fn (string $s): string => str_replace('\\', '', $s), explode('|', $m[1]));
    }

    public function test_the_fallback_htaccess_denies_every_top_level_directory_except_public(): void
    {
        $denied = self::denyList();
        $missing = [];
        foreach (scandir(TEST_ROOT) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'public' || !is_dir(TEST_ROOT . '/' . $entry)) {
                continue;
            }
            if (!in_array($entry, $denied, true)) {
                $missing[] = $entry;
            }
        }

        self::assertSame([], $missing, 'top-level directories not denied by .htaccess (would be web-reachable when the docroot is the project root)');
        foreach (['.git', 'storage', 'app', 'config', 'vendor', 'routes'] as $must) {
            self::assertContains($must, $denied);
        }
    }

    public function test_in_the_fallback_htaccess_the_deny_and_asset_rules_come_before_the_catch_all(): void
    {
        $h = self::read('.htaccess');
        $deny = strpos($h, '- [F,L]');
        $assets = strpos($h, 'public/assets/$1');
        $catchAll = strpos($h, 'public/index.php');

        self::assertNotFalse($deny);
        self::assertNotFalse($assets);
        self::assertNotFalse($catchAll);
        self::assertLessThan($catchAll, $deny, 'deny before the catch-all');
        self::assertLessThan($catchAll, $assets, 'assets before the catch-all, or they can never be served');
    }

    public function test_the_fallback_htaccess_forces_https_and_blocks_dotfiles_and_secrets(): void
    {
        $h = self::read('.htaccess');

        self::assertStringContainsString('RewriteCond %{HTTPS} !=on', $h);
        self::assertMatchesRegularExpression('/FilesMatch "\^\\\\\."\>\s*Require all denied/', $h);
        self::assertStringContainsString('composer', $h);
    }

    // ---- Apache: the real docroot -------------------------------------------

    public function test_the_public_htaccess_serves_only_the_front_controller_as_php(): void
    {
        $h = self::read('public/.htaccess');

        self::assertMatchesRegularExpression('/FilesMatch "\\\\\.\(\?i:php\)\$"\>\s*Require all denied/', $h);
        self::assertMatchesRegularExpression('/Files "index\.php"\>\s*Require all granted/', $h);
        self::assertStringContainsString('Options -Indexes', $h);
        self::assertStringContainsString('Header always unset X-Powered-By', $h);
        foreach (['sql', 'log', 'ini', 'md', 'lock', 'bak'] as $ext) {
            self::assertStringContainsString($ext, (string) preg_replace('/^.*\(\?i:\\\\\.\(([^)]+)\)\)\$.*$/s', '$1', $h), "{$ext} files blocked");
        }
    }

    // ---- static assets: minified, hashed, pre-compressed, cached correctly ------------------------------------------

    public function test_the_built_assets_are_minified_hashed_and_have_precompressed_copies_that_match(): void
    {
        $dir = TEST_ROOT . '/public/assets/build';
        $manifest = json_decode(self::read('public/assets/build/manifest.json'), true);
        self::assertIsArray($manifest);

        foreach (['app.css' => 'css', 'app.js' => 'js'] as $key => $ext) {
            $name = $manifest[$key] ?? '';
            self::assertMatchesRegularExpression('/^app\.[0-9a-f]{10}\.' . $ext . '$/', $name);
            $raw = (string) file_get_contents("{$dir}/{$name}");
            self::assertSame(substr(hash('sha256', $raw), 0, 10), substr($name, 4, 10), "{$name} is named after its own content");

            self::assertFileExists("{$dir}/{$name}.gz");
            self::assertSame($raw, gzdecode((string) file_get_contents("{$dir}/{$name}.gz")), "{$name}.gz decompresses to exactly the served file");
            self::assertFileExists("{$dir}/{$name}.br");
            self::assertLessThan(strlen($raw) * 0.6, filesize("{$dir}/{$name}.br"), "{$name}.br is a real Brotli copy, much smaller");
            self::assertLessThan(filesize("{$dir}/{$name}.gz"), filesize("{$dir}/{$name}.br"), 'Brotli beats gzip');
        }

        // the script really is minified: far smaller than its source, no comment banner, no line-per-statement layout
        $js = (string) file_get_contents("{$dir}/{$manifest['app.js']}");
        self::assertLessThan(filesize(TEST_ROOT . '/resources/js/app.js') * 0.65, strlen($js));
        self::assertStringNotContainsString('CRM front-end behaviours', $js);
        self::assertLessThan(5, substr_count($js, "\n"));
        // only the current build is left behind
        $left = array_filter(scandir($dir) ?: [], static fn (string $n): bool => preg_match('/^app\.[0-9a-f]{10}\./', $n) === 1);
        self::assertCount(6, $left, 'stale hashed files from older builds were removed');
    }

    public function test_the_public_htaccess_serves_precompressed_assets_and_caches_only_hashed_files_forever(): void
    {
        $h = self::read('public/.htaccess');

        self::assertMatchesRegularExpression('/RewriteCond %\{HTTP:Accept-Encoding\} \\\\bbr\\\\b\s+RewriteCond %\{REQUEST_FILENAME\}\.br -f\s+RewriteRule \^\(\.\+\)\\\.\(css\|js\)\$ \$1\.\$2\.br \[L,E=SERVE_BR:1\]/', $h);
        self::assertMatchesRegularExpression('/RewriteCond %\{HTTP:Accept-Encoding\} \\\\bgzip\\\\b\s+RewriteCond %\{REQUEST_FILENAME\}\.gz -f\s+RewriteRule/', $h);
        self::assertStringContainsString('Header set Content-Encoding br env=SERVE_BR', $h);
        self::assertStringContainsString('Header set Content-Encoding gzip env=SERVE_GZ', $h);
        self::assertStringContainsString('Header append Vary Accept-Encoding env=SERVE_BR', $h);
        self::assertMatchesRegularExpression('/ForceType text\/css/', $h);
        self::assertMatchesRegularExpression('/ForceType application\/javascript/', $h);

        // immutable (a year) is reserved for content-hashed names; anything a person can replace under the same name gets a month
        self::assertMatchesRegularExpression('/<FilesMatch "\^app\\\\\.\[0-9a-f\]\{10\}[^"]*">\s+Header set Cache-Control "public, max-age=31536000, immutable"/', $h);
        self::assertStringContainsString('Header set Cache-Control "public, max-age=2592000"', $h);
        self::assertSame(1, substr_count($h, 'immutable'), 'only one rule may mark files immutable');
    }

    public function test_every_storage_directory_that_holds_runtime_files_is_denied_by_its_own_htaccess(): void
    {
        foreach (['storage', 'storage/private', 'storage/imports', 'storage/exports'] as $dir) {
            self::assertStringContainsString('Require all denied', self::read($dir . '/.htaccess'), "{$dir}/.htaccess");
        }
        self::assertStringContainsString('engine off', self::read('storage/private/.htaccess'), 'PHP must not run in the uploads directory');
    }

    // ---- .env.example / secrets -----------------------------------------------

    public function test_env_example_ships_safe_production_defaults_and_no_secrets(): void
    {
        $env = [];
        foreach (explode("\n", self::read('.env.example')) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=([^#]*)/', $line, $m)) {
                $env[$m[1]] = trim($m[2], " \t\r\"");
            }
        }

        self::assertSame('production', $env['APP_ENV']);
        self::assertSame('false', $env['APP_DEBUG']);
        self::assertSame('true', $env['SESSION_SECURE']);
        self::assertSame('true', $env['HSTS_ENABLED']);
        self::assertStringStartsWith('https://', $env['APP_URL']);
        foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'CRON_SECRET', 'INTEGRATIONS_TURNSTILE_SECRET_KEY'] as $secret) {
            self::assertSame('', $env[$secret], "{$secret} must be blank in the example file");
        }
    }

    public function test_the_environment_file_is_ignored_by_git_and_the_session_cookie_is_locked_down(): void
    {
        self::assertMatchesRegularExpression('/^\.env$/m', self::read('.gitignore'));

        $session = self::read('config/session.php');
        self::assertStringContainsString("Env::bool('SESSION_SECURE', true)", $session, 'Secure cookie by default');
        self::assertStringContainsString("'http_only' => true", $session);
        self::assertMatchesRegularExpression("/'same_site'\s*=>\s*Env::get\('SESSION_SAME_SITE', 'Lax'\)/", $session);
    }

    // ---- Error pages ------------------------------------------------------------

    /** @return array<string,array{0:\Throwable,1:int,2:string}> */
    public static function failures(): array
    {
        return [
            '404 html' => [HttpException::notFound('x'), 404, '/things'],
            '403'      => [new AuthorizationException('no', 'x.y'), 403, '/things'],
            '500'      => [new \RuntimeException('boom'), 500, '/things'],
            '404 json' => [HttpException::notFound('x'), 404, '/api/things'],
            '500 json' => [new \RuntimeException('boom'), 500, '/api/things'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_error_responses_carry_the_security_headers_even_though_no_middleware_ran(\Throwable $e, int $status, string $uri): void
    {
        $app = TestApp::make([], 'production');
        $handler = new Handler($app, new Logger(sys_get_temp_dir() . '/crm_test_logs'));
        $req = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'], '');

        $res = $handler->render($req, $e);

        self::assertSame($status, $res->getStatus());
        self::assertSame('nosniff', $res->getHeader('X-Content-Type-Options'));
        self::assertSame('DENY', $res->getHeader('X-Frame-Options'));
        self::assertSame('same-origin', $res->getHeader('Cross-Origin-Opener-Policy'));
        self::assertNotNull($res->getHeader('Permissions-Policy'));
        self::assertStringContainsString("script-src 'none'", (string) $res->getHeader('Content-Security-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $res->getHeader('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $res->getHeader('Cache-Control'));
        // a 500 never leaks the exception message or a trace in production
        self::assertStringNotContainsString('boom', $res->getBody());
    }
}
