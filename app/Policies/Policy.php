<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\BranchScopeResolver;
use App\Auth\PermissionService;
use App\Models\User;

/**
 * Base policy. Concrete policies (LeadPolicy, CandidatePolicy, ...) arrive with
 * their feature phases. Every policy method layers: permission → branch scope →
 * record state → field grants.
 */
abstract class Policy
{
    public function __construct(
        protected readonly PermissionService $permissions,
        protected readonly BranchScopeResolver $branches,
    ) {
    }

    protected function can(User $user, string $permission): bool
    {
        return $this->permissions->userCan($user, $permission);
    }

    protected function canAny(User $user, string ...$permissions): bool
    {
        return $this->permissions->userCanAny($user, $permissions);
    }

    /** True if the record's branch is within the user's visible scope. */
    protected function inBranchScope(User $user, ?int $recordBranchId): bool
    {
        return $this->branches->resolve($user)->contains($recordBranchId);
    }

    /** Convenience: permission AND branch scope. */
    protected function canInBranch(User $user, string $permission, ?int $recordBranchId): bool
    {
        return $this->can($user, $permission) && $this->inBranchScope($user, $recordBranchId);
    }
}
