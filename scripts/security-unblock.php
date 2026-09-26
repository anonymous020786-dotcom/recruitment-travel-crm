<?php

declare(strict_types=1);

/**
 * Emergency exit: remove every IP allow/block rule, switch the automatic block off, and switch off the firewall's lockdown and
 * country rules, from the command line.
 * Use it if you blocked yourself out of the panel:
 *
 *   php scripts/security-unblock.php
 *
 * (Everything else — rate limits, the two-factor policy — stays as it is.)
 */

use App\Security\IpRules;
use App\Support\Application;
use App\Support\Db;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$removed = $app->get(IpRules::class)->clearAll();
$app->get(Db::class)->affectingStatement("DELETE FROM security_settings WHERE name IN ('autoblock.threshold', 'autoblock.minutes', 'fw.lockdown', 'fw.geo_scope')");

fwrite(\STDOUT, "Removed {$removed} IP rule(s); switched off the automatic block, firewall lockdown and country rules.\n");
exit(0);
