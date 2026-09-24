<?php

declare(strict_types=1);

/**
 * Go-live checklist for a host. Read-only; safe to run any time.
 *
 *   php scripts/preflight.php                         checks PHP, config, database, filesystem, cron, accounts
 *   php scripts/preflight.php --url=https://crm.example.in    ...and what the internet can reach
 *   php scripts/preflight.php --strict                warnings also fail the run
 *
 * Exit 0 = nothing failed, 1 = at least one FAIL (or a warning with --strict). See docs/DEPLOYMENT-HOSTINGER.md §
 * "Dry-run". Run it on the server after every deployment, and once from your own machine with --url.
 */

use App\Support\Application;
use App\Support\Db;
use App\Support\Preflight;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$opts = getopt('', ['url::', 'strict']);
$results = (new Preflight($app, $app->get(Db::class)))->run(isset($opts['url']) ? (string) $opts['url'] : null);

$mark = ['pass' => '  ok  ', 'warn' => ' WARN ', 'fail' => ' FAIL '];
$group = '';
$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
foreach ($results as $r) {
    if ($r['group'] !== $group) {
        $group = $r['group'];
        echo "\n{$group}\n";
    }
    $counts[$r['status']]++;
    printf("  [%s] %s%s\n", $mark[$r['status']], $r['name'], $r['status'] === 'pass' ? ($r['detail'] !== '' ? "  — {$r['detail']}" : '') : "\n           {$r['detail']}");
}

printf("\n%d passed, %d warning(s), %d failed.\n", $counts['pass'], $counts['warn'], $counts['fail']);
$bad = $counts['fail'] + (isset($opts['strict']) ? $counts['warn'] : 0);
echo $bad === 0 ? "Ready.\n" : "NOT ready — fix the items above.\n";
exit($bad === 0 ? 0 : 1);
