<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Security\IpRules;
use App\Support\Application;
use App\Support\Logger;
use Closure;

/**
 * Global: turns away addresses the super admin blocked in Admin → Security → IP rules (an allow rule always wins).
 *
 * Costs nothing while there are no rules — a flag set at boot — and one indexed query when there are. If that query fails the
 * request is let through (and the failure logged): a broken database must not lock everyone out of the site.
 */
final class IpFilter implements Middleware
{
    public function __construct(
        private readonly Application $app,
        private readonly Logger $logger,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) $this->app->config()->get('security.ip_rules_active', false)) {
            return $next($request);
        }
        try {
            $rules = $this->app->get(IpRules::class);
            $ip = $request->ip();
            if ($rules->verdict($ip) === 'block') {
                $rules->noteBlocked($ip);

                return $this->blocked();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('IP rules check failed: {m}', ['m' => $e->getMessage()]);
        }

        return $next($request);
    }

    private function blocked(): Response
    {
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>Access blocked</title>'
            . '<style>body{font:16px/1.6 system-ui,sans-serif;display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center;background:#f7f7f8;color:#1f2937}'
            . '.b{max-width:30rem;text-align:center;padding:2rem}h1{font-size:1.25rem;margin:0 0 .5rem}p{color:#4b5563}</style></head>'
            . '<body><div class="b"><h1>Access from your network is blocked</h1>'
            . '<p>If you think this is a mistake, please contact the site administrator.</p></div></body></html>';

        return Response::html($body, 403)->withHeader('Cache-Control', 'no-store');
    }
}
