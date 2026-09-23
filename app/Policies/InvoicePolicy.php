<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/** Invoice authorization: permission + the invoice's own branch. */
final class InvoicePolicy extends Policy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $this->canInBranch($user, 'invoices.view', $invoice->branchId);
    }

    public function edit(User $user, Invoice $invoice): bool
    {
        return $this->canInBranch($user, 'invoices.edit', $invoice->branchId);
    }

    /** Issuing is creating a binding document, so it needs the create permission. */
    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->canInBranch($user, 'invoices.create', $invoice->branchId);
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $this->canInBranch($user, 'invoices.void', $invoice->branchId);
    }
}
