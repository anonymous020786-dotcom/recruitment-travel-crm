<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Marks the request as a JSON client so that errors (and anything else that
 * inspects content negotiation) respond with JSON even when the caller omitted
 * an Accept header. Applied to the `api` route group.
 */
final class ForceJson implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->setAttribute('force_json', true);

        $response = $next($request);

        if ($response->getHeader('Content-Type') === null && $response->getBody() !== '') {
            $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        }

        return $response;
    }
}
