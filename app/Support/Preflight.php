<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Go-live checks for a host (Phase 12 deployment dry-run): PHP, configuration, database, filesystem, cron, accounts
 * and — given the site's URL — what the outside world can actually reach. Read-only: nothing is written or changed.
 *
 * Each result is `fail` (do not go live), `warn` (go live only knowingly) or `pass`. `scripts/preflight.php` prints
 * them and exits 1 when anything failed. `$ini` lets tests supply PHP settings without touching the real ones.
 */
final class Preflight
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** @var list<array{group:string,name:string,status:string,detail:string}> */
    private array $results = [];
    private string $group = '';

    /** @param array<string,string> $ini overrides for ini_get() */
    public function __construct(
        private readonly Application $app,
        private readonly ?Db $db = null,
        private readonly array $ini = [],
    ) {
    }

    /** @return list<array{group:string,name:string,status:string,detail:string}> */
    public function run(?string $url = null): array
    {
        $this->results = [];
        $this->php();
        $this->configuration();
        if ($this->db !== null) {
            $this->database();
            $this->cron();
            $this->accounts();
        }
        $this->filesystem();
        if ($url !== null) {
            $this->outside($url);
        }

        return $this->results;
    }

    /** @param list<array{status:string}> $results */
    public static function failed(array $results): int
    {
        return count(array_filter($results, static fn (array $r): bool => $r['status'] === self::FAIL));
    }

    // ---- groups ------------------------------------------------------------------

    private function php(): void
    {
        $this->group = 'PHP';
        $this->check('version ≥ 8.2', \PHP_VERSION_ID >= 80200, 'PHP ' . \PHP_VERSION, 'PHP ' . \PHP_VERSION . ' — select 8.2 or newer in hPanel');

        foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'zlib', 'json', 'openssl', 'ctype'] as $ext) {
            $this->check("extension {$ext}", extension_loaded($ext), 'loaded', 'missing — enable it in hPanel › PHP extensions');
        }
        foreach (['gd' => 'image uploads will not be re-encoded (metadata / trailing data kept)', 'phar' => 'the documents backup cannot be archived', 'intl' => 'optional'] as $ext => $why) {
            $this->check("extension {$ext}", extension_loaded($ext), 'loaded', "missing — {$why}", self::WARN);
        }

        $production = $this->app->isProduction();
        $this->check('display_errors is off', !$this->flag('display_errors'), 'off', 'ON — PHP would print errors and paths to visitors', $production ? self::FAIL : self::WARN);
        $mem = $this->bytes($this->iniValue('memory_limit'));
        $this->check('memory_limit ≥ 128M', $mem === 0 || $mem >= 128 * 1048576, $this->iniValue('memory_limit'), 'only ' . $this->iniValue('memory_limit') . ' — image processing and reports need more', self::WARN);

        $need = 12288 * 1024; // .env.example: UPLOAD_MAX_KB=12288
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $v = $this->bytes($this->iniValue($k));
            $this->check("{$k} ≥ 12M", $v >= $need, $this->iniValue($k), $this->iniValue($k) . ' — document uploads up to 12 MB would be refused by PHP', self::WARN);
        }
        $this->check('OPcache enabled', $this->flag('opcache.enable'), 'on', 'off — every request recompiles the application', self::WARN);
    }

    private function configuration(): void
    {
        $this->group = 'Configuration';
        $c = $this->app->config();
        $production = $this->app->isProduction();

        $this->check('APP_ENV=production', $production, 'production', 'APP_ENV=' . $this->app->environment() . ' — this host is not configured as production', self::FAIL);
        $this->check('APP_DEBUG=false', !$c->get('app.debug', false), 'off', 'ON — stack traces would be shown to users', $production ? self::FAIL : self::WARN);

        $key = (string) $c->get('app.key', '');
        $this->check('APP_KEY set (≥ 32 bytes)', strlen($key) >= 32 && $key !== 'base64:', 'set', 'missing or too short — generate: php -r "echo \'base64:\'.base64_encode(random_bytes(32));"');
        $url = (string) $c->get('app.url', '');
        $this->check('APP_URL is https', str_starts_with($url, 'https://') && !str_contains($url, 'example.com') && !str_contains($url, 'localhost'), $url, "APP_URL={$url} — must be the real https address", self::FAIL);
        $this->check('SESSION_SECURE=true', (bool) $c->get('session.secure', true), 'on', 'off — the session cookie would travel over plain http', $production ? self::FAIL : self::WARN);
        $this->check('HSTS enabled', (bool) $c->get('security.headers.hsts.enabled', false), 'on', 'off — browsers are not told to insist on https', self::WARN);
        $this->check('CRON_SECRET set', (string) $c->get('cron.secret', '') !== '', 'set', 'empty — cron scripts run without a secret argument', self::WARN);

        $driver = (string) $c->get('mail.driver', 'smtp');
        $smtpOk = $driver === 'smtp' && (string) $c->get('mail.smtp.host', '') !== '' && (string) $c->get('mail.smtp.username', '') !== '';
        $this->check('mail sends for real', $smtpOk, 'smtp', $driver === 'log' ? 'MAIL_DRIVER=log — password resets and reports would only be written to a log' : 'SMTP host/username missing', self::WARN);

        $envFile = $this->app->basePath('.env');
        if (is_file($envFile) && \DIRECTORY_SEPARATOR === '/') {
            $perms = fileperms($envFile) & 0777;
            $this->check('.env not world-readable', ($perms & 0007) === 0, decoct($perms), '.env is mode ' . decoct($perms) . ' — chmod 600 .env', self::WARN);
        }
        $this->check('.env is outside the web root', !is_file($this->app->basePath('public/.env')), 'not in public/', 'a .env exists inside public/', self::FAIL);
        $this->check('database password set', (string) $c->get('database.connections.mysql.password', '') !== '', 'set', 'empty database password', self::WARN);
    }

    private function database(): void
    {
        $this->group = 'Database';
        $db = $this->db;

        try {
            $version = (string) $db->selectValue('SELECT VERSION()');
            $this->add('connects', self::PASS, $version);
        } catch (\Throwable $e) {
            $this->add('connects', self::FAIL, 'cannot connect: ' . $e->getMessage());

            return;
        }

        $charset = (string) $db->selectValue('SELECT @@character_set_database');
        $this->check('database charset utf8mb4', $charset === 'utf8mb4', $charset, "{$charset} — non-ASCII names would be damaged; create the database as utf8mb4", self::WARN);

        $table = (string) $this->app->config()->get('database.migrations.table', 'schema_migrations');
        $dir = $this->app->basePath((string) $this->app->config()->get('database.migrations.path', 'database/migrations'));
        try {
            $applied = [];
            foreach ($db->select("SELECT version, checksum FROM `{$table}`") as $r) {
                $applied[(string) $r['version']] = (string) $r['checksum'];
            }
        } catch (\Throwable) {
            $this->add('migrations', self::FAIL, "the {$table} table is missing — run: php scripts/migrate.php");

            return;
        }
        $pending = [];
        $drift = [];
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            $version = basename($file, '.sql');
            if (!isset($applied[$version])) {
                $pending[] = $version;
            } elseif ($applied[$version] !== hash('sha256', (string) file_get_contents($file))) {
                $drift[] = $version;
            }
        }
        $this->check('all migrations applied', $pending === [], count($applied) . ' applied', count($pending) . ' pending (' . implode(', ', array_slice($pending, 0, 3)) . ') — run: php scripts/migrate.php');
        $this->check('no migration changed after being applied', $drift === [], 'none', 'changed: ' . implode(', ', $drift), self::WARN);
        $this->check('reference data seeded', (int) $db->selectValue('SELECT COUNT(*) FROM roles') > 0 && (int) $db->selectValue('SELECT COUNT(*) FROM lead_statuses') > 0, 'roles + statuses present', 'roles or lead statuses are empty — run: php scripts/seed.php');
    }

    private function cron(): void
    {
        $this->group = 'Scheduled jobs';
        $db = $this->db;
        try {
            $last = $db->selectValue('SELECT MAX(started_at) FROM cron_runs');
            $age = $last === null ? null : time() - (int) strtotime($last . ' UTC');
            $this->check('cron has run in the last 30 minutes', $age !== null && $age <= 1800, $age === null ? '' : intdiv($age, 60) . ' min ago', $age === null ? 'never — add the hPanel cron line (php cron/dispatch.php <CRON_SECRET> every 5 minutes)' : intdiv($age, 60) . ' minutes ago — is the cron line running?', self::WARN);

            $backup = $db->selectValue("SELECT MAX(started_at) FROM cron_runs WHERE job_name = 'backup' AND status = 'success'");
            $bage = $backup === null ? null : time() - (int) strtotime($backup . ' UTC');
            $this->check('a backup succeeded in the last 26 hours', $bage !== null && $bage <= 26 * 3600, $bage === null ? '' : intdiv($bage, 3600) . ' h ago', 'no successful backup yet — run: php scripts/backup.php', self::WARN);

            $failing = (int) $db->selectValue("SELECT COUNT(DISTINCT job_name) FROM cron_runs r WHERE status = 'failed' AND started_at > UTC_TIMESTAMP() - INTERVAL 1 DAY AND NOT EXISTS (SELECT 1 FROM cron_runs s WHERE s.job_name = r.job_name AND s.status = 'success' AND s.started_at > r.started_at)");
            $this->check('no job is failing', $failing === 0, 'none', "{$failing} job(s) failing — see /admin/cron", self::WARN);
        } catch (\Throwable $e) {
            $this->add('cron ledger readable', self::WARN, $e->getMessage());
        }
    }

    private function accounts(): void
    {
        $this->group = 'Accounts';
        $db = $this->db;
        try {
            $admins = $db->select("SELECT u.email, u.two_factor_enabled FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name IN ('super_admin','admin') AND u.is_active = 1 AND u.deleted_at IS NULL");
            $this->check('an active administrator exists', $admins !== [], count($admins) . ' active', 'none — create one: php scripts/create-admin.php --email=… --name=…');
            $without = array_filter($admins, static fn (array $a): bool => !(bool) $a['two_factor_enabled']);
            $this->check('administrators use two-factor', $without === [], 'all enrolled', count($without) . ' of ' . count($admins) . ' administrator(s) have no 2FA', self::WARN);
            $test = (int) $db->selectValue("SELECT COUNT(*) FROM users WHERE email LIKE '%@dev.local' OR email LIKE '%@example.com' OR email LIKE 'smoke.%'");
            $this->check('no dev/test accounts', $test === 0, 'none', "{$test} account(s) with a dev/test address — delete them before go-live", self::WARN);
        } catch (\Throwable $e) {
            $this->add('accounts readable', self::WARN, $e->getMessage());
        }
    }

    private function filesystem(): void
    {
        $this->group = 'Filesystem';
        foreach (['storage/logs', 'storage/private/documents', 'storage/private/backups', 'storage/imports', 'storage/exports', 'storage/cache'] as $dir) {
            $path = $this->app->basePath($dir);
            $this->check("{$dir} is writable", is_dir($path) && is_writable($path), 'writable', is_dir($path) ? 'not writable by PHP' : 'missing — create it (mode 0700)');
        }
        foreach (['storage/.htaccess', 'storage/private/.htaccess', 'public/.htaccess'] as $f) {
            $this->check("{$f} present", is_file($this->app->basePath($f)), 'present', 'missing — uploads or app files could become web-reachable');
        }
        $this->check('built assets present', is_file($this->app->basePath('public/assets/build/manifest.json')), 'manifest.json', 'public/assets/build/manifest.json missing — commit/deploy the built CSS and JS');
        $this->check('vendor/ installed', is_file($this->app->basePath('vendor/autoload.php')), 'present', 'vendor/autoload.php missing — run composer install --no-dev --optimize-autoloader');
        $this->check('no dev packages on the server', !is_dir($this->app->basePath('vendor/phpunit')), 'production install', 'vendor/phpunit exists — use composer install --no-dev', self::WARN);
        $this->check('authoritative class map', is_file($this->app->basePath('vendor/composer/autoload_classmap.php')) && str_contains((string) file_get_contents($this->app->basePath('vendor/composer/autoload_real.php')), 'setClassMapAuthoritative(true)'), 'optimised', 'autoloader not optimised — composer dump-autoload -o --classmap-authoritative', self::WARN);
    }

    /** What a visitor can reach from outside. Uses the network; run it against the deployed site. */
    private function outside(string $url): void
    {
        $this->group = 'From outside (' . $url . ')';
        $base = rtrim($url, '/');

        $get = function (string $path) use ($base): array {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 15, 'follow_location' => 0, 'header' => "User-Agent: crm-preflight\r\n"], 'ssl' => ['verify_peer' => true]]);
            $body = @file_get_contents($base . $path, false, $ctx);
            $headers = $http_response_header ?? [];
            $status = isset($headers[0]) && preg_match('#\s(\d{3})\s#', $headers[0], $m) ? (int) $m[1] : 0;
            $map = [];
            foreach ($headers as $h) {
                if (str_contains($h, ':')) {
                    [$k, $v] = explode(':', $h, 2);
                    $map[strtolower(trim($k))] = trim($v);
                }
            }

            return ['status' => $status, 'headers' => $map, 'body' => (string) $body];
        };

        $health = $get('/health');
        $this->check('/health answers 200', $health['status'] === 200, '200', 'HTTP ' . $health['status'] . ' — the site is not reachable at that address');
        $login = $get('/login');
        $this->check('/login answers 200', $login['status'] === 200, '200', 'HTTP ' . $login['status']);
        $h = $login['headers'];
        $this->check('Content-Security-Policy present', isset($h['content-security-policy']) && !str_contains($h['content-security-policy'], "'unsafe-inline'; script"), 'present', 'missing or weakened');
        $this->check('HSTS header present', isset($h['strict-transport-security']), 'present', 'missing — https-only, production-only header', self::WARN);
        $this->check('X-Powered-By absent', !isset($h['x-powered-by']), 'absent', 'PHP version is advertised: ' . ($h['x-powered-by'] ?? ''), self::WARN);
        $this->check('session cookie is Secure + HttpOnly', $this->cookieFlags($login['headers']), 'Secure; HttpOnly', 'the crm_session cookie lacks Secure or HttpOnly', self::FAIL);

        foreach (['/.env', '/.git/config', '/composer.json', '/storage/private/.htaccess', '/storage/logs/', '/vendor/autoload.php', '/config/app.php', '/database/schema/schema.sql', '/app/Support/Db.php', '/cron/dispatch.php', '/scripts/migrate.php', '/docs/00-ARCHITECTURE.md'] as $path) {
            // The app answers unknown paths with 404 and the server denies these with 403; a 200 (of anything) means
            // the file — or a page pretending to be it — is being served.
            $r = $get($path);
            $this->check("{$path} is not served", $r['status'] !== 200, 'HTTP ' . $r['status'], "HTTP {$r['status']} — this file is reachable from the internet", self::FAIL);
        }

        $http = preg_replace('#^https://#', 'http://', $base);
        if ($http !== $base) {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 15, 'follow_location' => 0]]);
            @file_get_contents($http . '/login', false, $ctx);
            $line = $http_response_header[0] ?? '';
            $loc = '';
            foreach ($http_response_header ?? [] as $hd) {
                if (stripos($hd, 'location:') === 0) {
                    $loc = trim(substr($hd, 9));
                }
            }
            $this->check('http redirects to https', str_contains($line, ' 301 ') && str_starts_with($loc, 'https://'), '301 → https', 'plain http is served or does not redirect: ' . $line, self::WARN);
        }
    }

    // ---- helpers ----------------------------------------------------------------------

    /** @param array<string,string> $headers */
    private function cookieFlags(array $headers): bool
    {
        // file_get_contents keeps only the last of repeated headers; the session cookie is the one we look for.
        $cookie = strtolower($headers['set-cookie'] ?? '');

        return $cookie === '' || (str_contains($cookie, 'secure') && str_contains($cookie, 'httponly'));
    }

    private function check(string $name, bool $ok, string $passDetail, string $failDetail, string $failStatus = self::FAIL): void
    {
        $this->add($name, $ok ? self::PASS : $failStatus, $ok ? $passDetail : $failDetail);
    }

    private function add(string $name, string $status, string $detail): void
    {
        $this->results[] = ['group' => $this->group, 'name' => $name, 'status' => $status, 'detail' => $detail];
    }

    private function iniValue(string $key): string
    {
        return $this->ini[$key] ?? (string) ini_get($key);
    }

    private function flag(string $key): bool
    {
        return in_array(strtolower($this->iniValue($key)), ['1', 'on', 'true', 'yes', 'stdout'], true);
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
