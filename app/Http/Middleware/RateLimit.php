<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use App\Support\Application;
use App\Support\RateLimiter;
use Closure;

/**
 * throttle:<bucket>  — DB-backed fixed-window limiter.
 *
 * Bucket config lives in config('rate_limits.buckets'). The key is built from
 * the bucket's `by` list: ip / user (session auth id) / email (request input).
 * When exceeded → 429 with Retry-After and X-RateLimit-* headers.
 */
final class RateLimit implements Middleware
{
    private string $bucket;

    /** @param list<string> $args */
    public function __construct(
        private readonly Application $app,
        private readonly RateLimiter $limiter,
        array $args = [],
    ) {
        $this->bucket = $args[0] ?? 'write';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) $this->app->config()->get("rate_limits.buckets.{$this->bucket}");
        if ($config === []) {
            return $next($request);
        }

        $max = (int) ($config['limit'] ?? 60);
        $window = (int) ($config['window_seconds'] ?? 60);
        $key = $this->resolveKey($request, (array) ($config['by'] ?? ['ip']));

        $hits = $this->limiter->hit($key, $window);

        if ($hits > $max) {
            $retryAfter = $this->limiter->availableIn($key, $window) ?: $window;

            throw new HttpException(429, 'Too many attempts. Please try again in a moment.', [
                'Retry-After'           => (string) $retryAfter,
                'X-RateLimit-Limit'     => (string) $max,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $max)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $max - $hits));
    }

    /** @param list<string> $by */
    private function resolveKey(Request $request, array $by): string
    {
        $parts = ["rl:{$this->bucket}"];

        foreach ($by as $dimension) {
            $parts[] = match ($dimension) {
                'ip'    => 'ip:' . $request->ip(),
                'user'  => 'user:' . ($this->authId($request) ?? 'guest'),
                'email' => 'email:' . strtolower(trim((string) $request->input('email', 'none'))),
                default => $dimension,
            };
        }

        return implode('|', $parts);
    }

    private function authId(Request $request): int|string|null
    {
        $session = $request->attribute('session');

        return $session instanceof Session ? $session->get('_auth_user_id') : null;
    }
}
