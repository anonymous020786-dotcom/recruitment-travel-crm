<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employer;
use App\Models\User;

/** Employer authorization: permission + branch scope (a null-branch employer is visible to org-wide users only). */
final class EmployerPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, 'employers.view', 'employers.view_all');
    }

    public function view(User $user, Employer $employer): bool
    {
        return $this->canAny($user, 'employers.view', 'employers.view_all')
            && ($this->can($user, 'employers.view_all') || $this->inBranchScope($user, $employer->branchId));
    }

    public function update(User $user, Employer $employer): bool
    {
        return $this->can($user, 'employers.edit') && $this->inBranchScope($user, $employer->branchId);
    }

    public function delete(User $user, Employer $employer): bool
    {
        return $this->can($user, 'employers.delete') && $this->inBranchScope($user, $employer->branchId);
    }

    public function manageContacts(User $user, Employer $employer): bool
    {
        return $this->can($user, 'employers.contacts.manage') && $this->inBranchScope($user, $employer->branchId);
    }
}
