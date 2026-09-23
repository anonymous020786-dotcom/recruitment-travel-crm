<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

/** Application authorization: permission + the application's own branch (its candidate's branch). */
final class ApplicationPolicy extends Policy
{
    public function view(User $user, Application $app): bool
    {
        return $this->canAny($user, 'applications.view', 'applications.view_all')
            && ($this->can($user, 'applications.view_all') || $this->inBranchScope($user, $app->branchId));
    }

    public function changeStatus(User $user, Application $app): bool
    {
        return $this->can($user, 'applications.change_status') && $this->inBranchScope($user, $app->branchId);
    }

    public function overrideStatus(User $user, Application $app): bool
    {
        return $this->can($user, 'applications.override_status') && $this->inBranchScope($user, $app->branchId);
    }

    public function cancel(User $user, Application $app): bool
    {
        return $this->can($user, 'applications.delete') && $this->inBranchScope($user, $app->branchId);
    }
}
