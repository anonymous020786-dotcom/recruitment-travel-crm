<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Support\Application;
use Closure;

/**
 * In production, force HTTPS with a 301 to the canonical APP_URL host + the
 * requested path/query. The redirect target is built from config('app.url'),
 * never from the (spoofable) Host header. Non-production is left alone so local
 * HTTP dev works.
 */
final class EnforceHttps implements Middleware
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->app->isProduction() && !$request->isSecure()) {
            $base = rtrim((string) $this->app->config()->get('app.url', ''), '/');
            $base = preg_replace('#^http://#i', 'https://', $base) ?: $base;

            $query = (string) parse_url($request->fullUrl(), PHP_URL_QUERY);
            $target = $base . $request->path() . ($query !== '' ? '?' . $query : '');

            return Response::redirect($target, 301, allowExternal: true)
                ->withHeader('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
