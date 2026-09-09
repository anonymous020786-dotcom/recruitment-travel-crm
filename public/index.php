<?php

declare(strict_types=1);

/**
 * Front controller. Every web request is rewritten here by public/.htaccess.
 *
 * Step 1.1: bootstrap only, with a minimal liveness endpoint so deployment can
 * be verified. Step 1.3 inserts the Router; Step 1.4 the middleware pipeline.
 */

use App\Support\Application;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$path = rtrim(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), '/') ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// --- Temporary liveness endpoint (replaced by the Router in Step 1.3) ---------
if ($path === '/health' && $method === 'GET') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode([
        'status'  => 'ok',
        'app'     => $app->config()->get('app.name'),
        'env'     => $app->environment(),
        'version' => Application::VERSION,
        'time'    => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
    return;
}

// --- Placeholder until the Router lands --------------------------------------
http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
echo "Recruitment & Travel CRM — foundation bootstrap OK.\n";
echo "Environment: " . $app->environment() . "\n";
echo "The HTTP router is delivered in Phase 1, Step 1.3.\n";
