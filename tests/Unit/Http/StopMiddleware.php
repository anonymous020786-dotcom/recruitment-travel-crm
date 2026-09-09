<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

final class StopMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return Response::text('stopped');
    }
}
