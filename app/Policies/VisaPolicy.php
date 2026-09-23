<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VisaApplication;

/** Visa authorization: permission + the candidate's branch. */
final class VisaPolicy extends Policy
{
    public function view(User $user, VisaApplication $visa): bool
    {
        return $this->canInBranch($user, 'visa.view', $visa->branchId);
    }

    public function edit(User $user, VisaApplication $visa): bool
    {
        return $this->canInBranch($user, 'visa.edit', $visa->branchId);
    }

    public function changeStatus(User $user, VisaApplication $visa): bool
    {
        return $this->canInBranch($user, 'visa.change_status', $visa->branchId);
    }

    public function overrideStatus(User $user, VisaApplication $visa): bool
    {
        return $this->canInBranch($user, 'visa.override_status', $visa->branchId);
    }

    public function delete(User $user, VisaApplication $visa): bool
    {
        return $this->canInBranch($user, 'visa.delete', $visa->branchId);
    }
}
