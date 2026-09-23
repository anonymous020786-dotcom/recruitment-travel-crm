<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Placement;
use App\Models\User;

/** Placement authorization: permission + the placement's branch. */
final class PlacementPolicy extends Policy
{
    public function view(User $user, Placement $placement): bool
    {
        return $this->canInBranch($user, 'travel.view', $placement->branchId);
    }

    public function edit(User $user, Placement $placement): bool
    {
        return $this->canInBranch($user, 'travel.placement.manage', $placement->branchId);
    }
}
