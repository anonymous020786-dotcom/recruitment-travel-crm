<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Support\Application;
use App\Support\HtmlMinifier;
use Closure;

/**
 * Minifies the HTML the application renders (see HtmlMinifier for exactly what that means). It is the outermost middleware of
 * the web groups, so it sees the finished page. Off when `app.minify_html` is false (HTML_MINIFY=0) and while APP_DEBUG is on,
 * so a developer reading "view source" sees the templates as written. Only complete `text/html` bodies are touched — never
 * downloads, JSON, CSV or streamed responses.
 */
final class MinifyHtml implements Middleware
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $config = $this->app->config();

        if (!(bool) $config->get('app.minify_html', true) || (bool) $config->get('app.debug', false)) {
            return $response;
        }
        $type = strtolower((string) $response->getHeader('Content-Type'));
        if (!str_starts_with($type, 'text/html') || $response->getHeader('Content-Disposition') !== null || $response->getBody() === '') {
            return $response;
        }

        return $response->withBody(HtmlMinifier::minify($response->getBody()));
    }
}
