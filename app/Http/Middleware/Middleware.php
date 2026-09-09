<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * A middleware receives the request and a $next callable. It either returns a
 * Response (short-circuit) or calls $next($request) and (optionally) post-
 * processes the returned Response.
 */
interface Middleware
{
    public function handle(Request $request, Closure $next): Response;
}
