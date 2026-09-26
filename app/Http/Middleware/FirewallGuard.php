<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Security\Firewall;
use App\Support\Application;
use App\Support\Logger;
use Closure;

/**
 * Global: the application firewall (Admin → Security → Firewall). Runs right after the IP filter, before sessions, routing
 * or any database work of the page itself. A blocked request gets a plain 403 with a reference the super admin can find in
 * the firewall log. If the firewall itself fails, the request is let through and the failure logged — a bug in the firewall
 * must never take the whole site down.
 */
final class FirewallGuard implements Middleware
{
    public function __construct(
        private readonly Application $app,
        private readonly Logger $logger,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $firewall = $this->app->get(Firewall::class);
            if ($firewall->enabled()) {
                $verdict = $request->attribute('ip_verdict');
                $result = $firewall->guard($request, is_string($verdict) ? $verdict : null);
                if ($result['blocked']) {
                    return $this->blocked($result['reference']);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('firewall check failed: {m}', ['m' => $e->getMessage()]);
        }

        return $next($request);
    }

    private function blocked(string $reference): Response
    {
        $ref = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>Request blocked</title>'
            . '<style>body{font:16px/1.6 system-ui,sans-serif;display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center;background:#f7f7f8;color:#1f2937}'
            . '.b{max-width:32rem;text-align:center;padding:2rem}h1{font-size:1.25rem;margin:0 0 .5rem}p{color:#4b5563}code{font-size:.85rem}</style></head>'
            . '<body><div class="b"><h1>This request was blocked for security reasons</h1>'
            . '<p>If you think this is a mistake, contact us and quote this reference:</p><p><code>' . $ref . '</code></p></div></body></html>';

        return Response::html($body, 403)->withHeader('Cache-Control', 'no-store');
    }
}
