<?php

declare(strict_types=1);

/**
 * Accessibility / responsiveness / SEO audit of rendered pages (Phase 12). Signs in, fetches each page and runs
 * App\Support\HtmlAudit on the HTML.
 *
 *   AUDIT_EMAIL=admin@… AUDIT_PASSWORD=… php scripts/html-audit.php --base=http://127.0.0.1:8099 [--paths=/leads,/dashboard]
 *   php scripts/html-audit.php --base=https://your-domain --public-only        (public pages, no sign-in)
 *
 * Without --paths it audits a built-in list of CRM screens (ids are looked up in the database) and the public pages.
 * Exit 1 if any ERROR finding is left. Needs the curl extension.
 */

use App\Support\Application;
use App\Support\Db;
use App\Support\HtmlAudit;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$opts = getopt('', ['base:', 'paths::', 'public-only', 'verbose']);
$base = rtrim((string) ($opts['base'] ?? ''), '/');
if ($base === '') {
    fwrite(STDERR, "Usage: php scripts/html-audit.php --base=<url> [--paths=/a,/b] [--public-only]\n");
    exit(2);
}

$jar = tempnam(sys_get_temp_dir(), 'audit');
$fetch = static function (string $path, array $post = []) use ($base, $jar): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $headers = [];
    foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => substr($raw, $size)];
};

$pages = [['/login', 'app']];   // audited before signing in (a signed-in visit redirects away)
$public = ['/', '/about', '/contact'];

if (!isset($opts['public-only'])) {
    $email = (string) getenv('AUDIT_EMAIL');
    $password = (string) getenv('AUDIT_PASSWORD');
    $login = $fetch('/login');
    preg_match('/name="_token" value="([^"]+)"/', $login['body'], $m);
    $r = $fetch('/login', ['_token' => $m[1] ?? '', 'email' => $email, 'password' => $password]);
    if ($r['status'] !== 302) {
        fwrite(STDERR, "Sign-in failed (HTTP {$r['status']}). Set AUDIT_EMAIL / AUDIT_PASSWORD.\n");
        exit(2);
    }

    if (isset($opts['paths'])) {
        $pages = array_map(fn ($p) => [$p, 'app'], explode(',', (string) $opts['paths']));
    } else {
        $db = $app->get(Db::class);
        $id = static fn (string $table): ?string => ($v = $db->selectValue("SELECT public_id FROM `{$table}` ORDER BY id LIMIT 1")) !== null ? (string) $v : null;
        $list = ['/login-app-skip', '/dashboard', '/leads', '/leads/create', '/candidates', '/applications', '/jobs', '/employers', '/interviews', '/medical', '/visa', '/travel',
            '/tours/packages', '/tours/bookings', '/invoices', '/payments', '/refunds', '/reports', '/reports/collections', '/followups', '/exports',
            '/account/profile', '/account/two-factor', '/admin/cron'];
        foreach (['leads' => '/leads/', 'candidates' => '/candidates/', 'applications' => '/applications/', 'invoices' => '/invoices/', 'payments' => '/payments/', 'employers' => '/employers/', 'jobs' => '/jobs/'] as $table => $prefix) {
            if (($pid = $id($table)) !== null) {
                $list[] = $prefix . $pid;
            }
        }
        foreach ($list as $p) {
            if ($p !== '/login-app-skip') {
                $pages[] = [$p, 'app'];
            }
        }
    }
}
foreach ($public as $p) {
    $pages[] = [$p, 'public'];
}

$audit = new HtmlAudit();
$errors = $warnings = 0;
$byRule = [];
foreach ($pages as [$path, $kind]) {
    $r = $fetch($path);
    if ($r['status'] !== 200) {
        printf("%-34s HTTP %d (skipped)\n", $path, $r['status']);
        continue;
    }
    $found = $audit->check($r['body'], $kind, $r['headers']);
    $e = count(array_filter($found, static fn ($f) => $f['severity'] === HtmlAudit::ERROR));
    $w = count($found) - $e;
    $errors += $e;
    $warnings += $w;
    printf("%-34s %s  %d error(s), %d warning(s)\n", $path, $kind, $e, $w);
    foreach ($found as $f) {
        $byRule[$f['rule']][$path] = $f;
        if (isset($opts['verbose']) || $f['severity'] === HtmlAudit::ERROR) {
            printf("    %-5s %-16s %s\n          %s\n", strtoupper($f['severity']), $f['rule'], $f['message'], $f['snippet']);
        }
    }
}

echo "\nBy rule (pages affected):\n";
ksort($byRule);
foreach ($byRule as $rule => $pagesHit) {
    $sample = reset($pagesHit);
    printf("  %-18s %-5s %2d page(s)  %s\n", $rule, $sample['severity'], count($pagesHit), $sample['message']);
}
printf("\n%d error(s), %d warning(s) across %d page(s).\n", $errors, $warnings, count($pages));
@unlink($jar);
exit($errors === 0 ? 0 : 1);
