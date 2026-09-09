<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Placeholder middleware. Registered for aliases whose real implementation
 * arrives in a later Phase 1 step (auth, can, throttle, ...). It does nothing
 * but continue the pipeline, so routes can already declare their intended
 * middleware without breaking dispatch.
 */
final class Passthrough implements Middleware
{
    /** @param list<string> $args */
    public function __construct(public readonly array $args = [])
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
