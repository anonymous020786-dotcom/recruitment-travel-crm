<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\AuthorizationException;
use App\Models\User;
use App\Support\Container;

/**
 * Central authorization entry point.
 *
 *   $gate->allows('leads.view')                  raw permission check
 *   $gate->allows('view', $lead)                 dispatch to LeadPolicy::view($user, $lead)
 *   $gate->authorize('update', $candidate)       throws AuthorizationException on denial
 *   $gate->forUser($otherUser)->allows(...)      check as a specific user
 *
 * With no user (guest / CLI), everything is denied unless the ability is a
 * closure-defined gate that opts in.
 */
final class Gate
{
    /** @var array<class-string,class-string> model class => policy class */
    private array $policies = [];

    /** @var array<string,callable> ability => fn(?User $user, mixed ...$args): bool */
    private array $abilities = [];

    public function __construct(
        private readonly Container $container,
        private readonly PermissionService $permissions,
        private readonly Auth $auth,
        private readonly ?User $user = null,
    ) {
    }

    public function forUser(?User $user): self
    {
        $gate = new self($this->container, $this->permissions, $this->auth, $user);
        $gate->policies = $this->policies;
        $gate->abilities = $this->abilities;

        return $gate;
    }

    /** @param class-string $model @param class-string $policy */
    public function policy(string $model, string $policy): void
    {
        $this->policies[$model] = $policy;
    }

    public function define(string $ability, callable $callback): void
    {
        $this->abilities[$ability] = $callback;
    }

    public function allows(string $ability, mixed ...$arguments): bool
    {
        return $this->check($ability, $arguments);
    }

    public function denies(string $ability, mixed ...$arguments): bool
    {
        return !$this->check($ability, $arguments);
    }

    public function any(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if ($this->check($ability, $arguments)) {
                return true;
            }
        }

        return false;
    }

    public function authorize(string $ability, mixed ...$arguments): void
    {
        if (!$this->check($ability, $arguments)) {
            throw AuthorizationException::forPermission($ability);
        }
    }

    private function currentUser(): ?User
    {
        return $this->user ?? $this->auth->user();
    }

    private function check(string $ability, array $arguments): bool
    {
        $user = $this->currentUser();

        // 1. Closure-defined ability.
        if (isset($this->abilities[$ability])) {
            return (bool) ($this->abilities[$ability])($user, ...$arguments);
        }

        if ($user === null) {
            return false;
        }

        if ($user->roleName === 'super_admin') {
            return true;
        }

        // 2. Policy dispatch when the first argument is a model with a policy.
        $model = $arguments[0] ?? null;
        $modelClass = is_object($model) ? $model::class : (is_string($model) && class_exists($model) ? $model : null);

        if ($modelClass !== null && isset($this->policies[$modelClass])) {
            $policy = $this->container->make($this->policies[$modelClass]);
            if (method_exists($policy, $ability)) {
                return (bool) $policy->{$ability}($user, ...$arguments);
            }
        }

        // 3. Raw permission name ("module.action").
        if (str_contains($ability, '.')) {
            return $this->permissions->userCan($user, $ability);
        }

        return false;
    }
}
