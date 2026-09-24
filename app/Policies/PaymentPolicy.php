<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/** Payment authorization: permission + the payment's own branch. */
final class PaymentPolicy extends Policy
{
    public function view(User $user, Payment $payment): bool
    {
        return $this->canInBranch($user, 'payments.view', $payment->branchId);
    }

    public function edit(User $user, Payment $payment): bool
    {
        return $this->canInBranch($user, 'payments.edit', $payment->branchId);
    }

    public function allocate(User $user, Payment $payment): bool
    {
        return $this->canInBranch($user, 'allocations.manage', $payment->branchId);
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $this->canInBranch($user, 'payments.reverse', $payment->branchId);
    }

    public function viewReceipt(User $user, Payment $payment): bool
    {
        return $this->canInBranch($user, 'receipts.view', $payment->branchId);
    }
}
