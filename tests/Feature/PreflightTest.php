<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Preflight;
use Tests\Support\DbTestCase;

/** The go-live checker: it must fail loudly on a misconfigured host and stay quiet on a correct one. */
final class PreflightTest extends DbTestCase
{
    /** @param array<string,mixed> $config @param array<string,string> $ini @return array<string,array{status:string,detail:string}> "group / name" => result */
    private function results(array $config = [], array $ini = [], bool $withDb = false): array
    {
        foreach ($config as $k => $v) {
            $this->app->config()->set($k, $v);
        }
        $out = [];
        foreach ((new Preflight($this->app, $withDb ? $this->db : null, $ini))->run() as $r) {
            $out[$r['group'] . ' / ' . $r['name']] = ['status' => $r['status'], 'detail' => $r['detail']];
        }

        return $out;
    }

    /** A configuration a real production host would have. */
    private function productionConfig(): array
    {
        return [
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://crm.acmetravel.in',
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)), 'session.secure' => true,
            'security.headers.hsts.enabled' => true, 'cron.secret' => 'secret', 'mail.driver' => 'smtp',
            'mail.smtp.host' => 'smtp.hostinger.com', 'mail.smtp.username' => 'no-reply@acmetravel.in',
        ];
    }

    private function st(array $r, string $key): string
    {
        self::assertArrayHasKey($key, $r, 'check missing: ' . $key);

        return $r[$key]['status'];
    }

    public function test_a_correct_production_configuration_has_no_failures(): void
    {
        $r = $this->results($this->productionConfig(), ['display_errors' => '0', 'opcache.enable' => '1']);

        foreach ($r as $key => $res) {
            if (str_starts_with($key, 'Configuration /') || str_starts_with($key, 'PHP / display_errors')) {
                self::assertNotSame(Preflight::FAIL, $res['status'], "{$key}: {$res['detail']}");
            }
        }
        self::assertSame(Preflight::PASS, $this->st($r, 'Configuration / APP_ENV=production'));
        self::assertSame(Preflight::PASS, $this->st($r, 'Configuration / APP_URL is https'));
        self::assertSame(Preflight::PASS, $this->st($r, 'Configuration / mail sends for real'));
    }

    public function test_a_development_configuration_is_not_ready(): void
    {
        $r = $this->results(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://localhost:8870', 'app.key' => '', 'session.secure' => false, 'cron.secret' => '', 'mail.driver' => 'log']);

        self::assertSame(Preflight::FAIL, $this->st($r, 'Configuration / APP_ENV=production'));
        self::assertSame(Preflight::WARN, $this->st($r, 'Configuration / APP_DEBUG=false'), 'debug is only a hard failure when the env says production');
        self::assertSame(Preflight::FAIL, $this->st($r, 'Configuration / APP_KEY set (≥ 32 bytes)'));
        self::assertSame(Preflight::FAIL, $this->st($r, 'Configuration / APP_URL is https'));
        self::assertSame(Preflight::WARN, $this->st($r, 'Configuration / CRON_SECRET set'));
        self::assertSame(Preflight::WARN, $this->st($r, 'Configuration / mail sends for real'));
    }

    public function test_debug_secure_cookie_and_display_errors_are_hard_failures_in_production(): void
    {
        $r = $this->results(['app.debug' => true, 'session.secure' => false] + $this->productionConfig(), ['display_errors' => 'On']);

        self::assertSame(Preflight::FAIL, $this->st($r, 'Configuration / APP_DEBUG=false'));
        self::assertSame(Preflight::FAIL, $this->st($r, 'Configuration / SESSION_SECURE=true'));
        self::assertSame(Preflight::FAIL, $this->st($r, 'PHP / display_errors is off'));
    }

    public function test_php_limits_are_judged_from_the_ini_values(): void
    {
        $small = $this->results([], ['upload_max_filesize' => '2M', 'post_max_size' => '8M', 'memory_limit' => '64M']);
        self::assertSame(Preflight::WARN, $this->st($small, 'PHP / upload_max_filesize ≥ 12M'));
        self::assertSame(Preflight::WARN, $this->st($small, 'PHP / post_max_size ≥ 12M'));
        self::assertSame(Preflight::WARN, $this->st($small, 'PHP / memory_limit ≥ 128M'));

        $big = $this->results([], ['upload_max_filesize' => '64M', 'post_max_size' => '1G', 'memory_limit' => '-1']);
        self::assertSame(Preflight::PASS, $this->st($big, 'PHP / upload_max_filesize ≥ 12M'));
        self::assertSame(Preflight::PASS, $this->st($big, 'PHP / post_max_size ≥ 12M'));
        self::assertSame(Preflight::PASS, $this->st($big, 'PHP / memory_limit ≥ 128M'), 'unlimited is fine');
    }

    public function test_database_and_migration_checks_run_against_the_real_schema(): void
    {
        $r = $this->results([], [], withDb: true);

        self::assertSame(Preflight::PASS, $this->st($r, 'Database / connects'));
        self::assertSame(Preflight::PASS, $this->st($r, 'Database / all migrations applied'));
        self::assertSame(Preflight::PASS, $this->st($r, 'Database / no migration changed after being applied'));
        self::assertSame(Preflight::PASS, $this->st($r, 'Database / reference data seeded'));
        self::assertArrayHasKey('Scheduled jobs / cron has run in the last 30 minutes', $r);
        self::assertArrayHasKey('Accounts / administrators use two-factor', $r);
    }

    public function test_a_pending_migration_is_reported_as_a_failure(): void
    {
        $version = '9999_preflight_probe';
        $dir = TEST_ROOT . '/database/migrations';
        file_put_contents("{$dir}/{$version}.sql", "-- probe, never applied\n");

        try {
            $r = $this->results([], [], withDb: true);
        } finally {
            @unlink("{$dir}/{$version}.sql");
        }

        self::assertSame(Preflight::FAIL, $this->st($r, 'Database / all migrations applied'));
        self::assertStringContainsString($version, $r['Database / all migrations applied']['detail']);
    }

    public function test_the_filesystem_checks_pass_for_this_project_layout(): void
    {
        $r = $this->results();

        foreach (['storage/logs is writable', 'storage/private/documents is writable', 'storage/.htaccess present', 'public/.htaccess present', 'built assets present', 'vendor/ installed'] as $name) {
            self::assertSame(Preflight::PASS, $this->st($r, 'Filesystem / ' . $name), $name);
        }
    }

    public function test_failed_counts_only_failures(): void
    {
        self::assertSame(2, Preflight::failed([['status' => 'fail'], ['status' => 'warn'], ['status' => 'pass'], ['status' => 'fail']]));
        self::assertSame(0, Preflight::failed([]));
    }
}
