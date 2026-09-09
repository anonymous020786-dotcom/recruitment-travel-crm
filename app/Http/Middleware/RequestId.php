<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Support\Logger;
use App\Support\Ulid;
use Closure;

/**
 * Assigns a correlation id to the request: taken from an inbound X-Request-Id
 * header if it is well-formed, otherwise generated. Exposed on the request
 * (`request_id` attribute), echoed as `X-Request-Id`, and attached to every
 * log line for this request.
 */
final class RequestId implements Middleware
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');
        $id = ($incoming !== null && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1)
            ? $incoming
            : Ulid::generate();

        $request->setAttribute('request_id', $id);
        $this->logger->withContext('request_id', $id);

        return $next($request)->withHeader('X-Request-Id', $id);
    }
}
