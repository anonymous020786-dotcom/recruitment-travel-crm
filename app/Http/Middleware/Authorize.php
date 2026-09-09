<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * `can:<ability>` — server-side authorization gate on a route.
 *
 *   can:leads.view                     raw permission
 *   can:view,App\Models\Lead           policy ability + model class (record checks
 *                                      still happen in the controller/service)
 *
 * Runs after `auth`, so an unauthenticated request has already been redirected.
 * A denial here is a 403.
 */
final class Authorize implements Middleware
{
    private string $ability;
    private ?string $modelClass;

    /** @param list<string> $args */
    public function __construct(
        private readonly Gate $gate,
        private readonly Auth $auth,
        array $args = [],
    ) {
        $this->ability = $args[0] ?? '';
        $this->modelClass = isset($args[1]) ? str_replace('/', '\\', $args[1]) : null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->ability === '') {
            return $next($request);
        }

        if ($this->auth->guest()) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $allowed = $this->modelClass !== null
            ? $this->gate->allows($this->ability, $this->modelClass)
            : $this->gate->allows($this->ability);

        if (!$allowed) {
            throw new AuthorizationException("Not authorized for [{$this->ability}].", $this->ability);
        }

        return $next($request);
    }
}
