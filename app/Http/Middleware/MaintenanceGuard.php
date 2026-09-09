<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Support\Application;
use Closure;

/**
 * Serves a 503 while the app is in maintenance mode. Toggled without a database
 * by `scripts/down.php` / `scripts/up.php`, which write/remove
 * storage/framework/down (JSON: secret, retry, allow []). A visitor with the
 * correct `?secret=` gets a bypass cookie; configured IPs are always allowed.
 *
 * Role-based bypass (super_admin) is layered on in Step 1.6 once auth exists.
 */
final class MaintenanceGuard implements Middleware
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $file = $this->app->storagePath('framework/down');

        if (!is_file($file) && !(bool) $this->app->config()->get('app.maintenance.enabled', false)) {
            return $next($request);
        }

        $data = is_file($file)
            ? (json_decode((string) file_get_contents($file), true) ?: [])
            : [];

        $secret = (string) ($data['secret'] ?? '');
        $retry = (int) ($data['retry'] ?? 120);
        $allowed = (array) ($data['allow'] ?? []);

        if (in_array($request->ip(), $allowed, true)) {
            return $next($request);
        }

        $cookieName = 'crm_down_bypass';
        if ($secret !== '') {
            if (hash_equals($secret, (string) $request->query('secret', ''))) {
                return $next($request)->withCookie($cookieName, hash('sha256', $secret), [
                    'expires'  => time() + 3600,
                    'path'     => '/',
                    'secure'   => $request->isSecure(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            if (hash_equals(hash('sha256', $secret), (string) $request->cookie($cookieName, ''))) {
                return $next($request);
            }
        }

        $retryAfter = max(1, $retry);
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>Under maintenance</title>'
            . '<style>body{font:16px/1.6 system-ui,sans-serif;display:flex;min-height:100vh;margin:0;'
            . 'align-items:center;justify-content:center;background:#f7f7f8;color:#1f2937}'
            . '.b{max-width:30rem;text-align:center;padding:2rem}h1{font-size:1.25rem;margin:0 0 .5rem}'
            . 'p{color:#4b5563}</style></head><body><div class="b"><h1>We\'ll be right back</h1>'
            . '<p>The system is briefly unavailable for maintenance. Please try again shortly.</p>'
            . '</div></body></html>';

        return Response::html($body, 503)
            ->withHeader('Retry-After', (string) $retryAfter)
            ->withHeader('Cache-Control', 'no-store');
    }
}
