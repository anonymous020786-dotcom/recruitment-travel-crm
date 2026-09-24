<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Refund;
use App\Models\User;

/** Refund authorization: permission + the refund's own branch. */
final class RefundPolicy extends Policy
{
    public function view(User $user, Refund $refund): bool
    {
        return $this->canInBranch($user, 'refunds.view', $refund->branchId);
    }

    public function approve(User $user, Refund $refund): bool
    {
        return $this->canInBranch($user, 'refunds.approve', $refund->branchId);
    }

    public function reject(User $user, Refund $refund): bool
    {
        return $this->canInBranch($user, 'refunds.reject', $refund->branchId);
    }

    public function markPaid(User $user, Refund $refund): bool
    {
        return $this->canInBranch($user, 'refunds.mark_paid', $refund->branchId);
    }
}
