<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Support\Application;
use App\Support\Db;

/**
 * Works out a user's BranchScope: organisation-wide if the user is flagged
 * `is_org_wide` or holds an org-wide role, otherwise the union of
 * `user_branches` (falling back to `primary_branch_id`). Memoised per user.
 */
final class BranchScopeResolver
{
    /** @var array<int,BranchScope> */
    private array $cache = [];

    public function __construct(
        private readonly Application $app,
        private readonly Db $db,
    ) {
    }

    public function resolve(User $user): BranchScope
    {
        return $this->cache[$user->id] ??= $this->compute($user);
    }

    private function compute(User $user): BranchScope
    {
        $orgWideRoles = (array) $this->app->config()->get('auth.org_wide_roles', ['super_admin', 'admin']);

        if ($user->isOrgWide || in_array($user->roleName, $orgWideRoles, true)) {
            return BranchScope::orgWide();
        }

        $ids = array_map(
            static fn ($r) => (int) $r['branch_id'],
            $this->db->select('SELECT branch_id FROM user_branches WHERE user_id = :uid', ['uid' => $user->id]),
        );

        if ($ids === [] && $user->primaryBranchId !== null) {
            $ids = [$user->primaryBranchId];
        }

        return BranchScope::of($ids);
    }
}
