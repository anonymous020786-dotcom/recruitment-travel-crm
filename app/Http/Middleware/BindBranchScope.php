<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Auth\BranchScope;
use App\Auth\BranchScopeResolver;
use App\Http\Request;
use App\Http\Response;
use App\Support\Application;
use Closure;

/**
 * `branch` — resolves the acting user's BranchScope and makes it available to
 * repositories/policies (request attribute `branch_scope` + container instance).
 * Every branch-scoped list/detail query is then filtered to that set,
 * preventing cross-branch data leakage. Runs after `auth`.
 */
final class BindBranchScope implements Middleware
{
    public function __construct(
        private readonly Application $app,
        private readonly Auth $auth,
        private readonly BranchScopeResolver $resolver,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->user();

        $scope = $user !== null ? $this->resolver->resolve($user) : BranchScope::of([]);

        $request->setAttribute('branch_scope', $scope);
        $this->app->instance(BranchScope::class, $scope);

        return $next($request);
    }
}
