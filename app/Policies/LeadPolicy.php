<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Lead authorization: permission + branch scope + record state.
 * Registered on the Gate for App\Models\Lead.
 */
final class LeadPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, 'leads.view', 'leads.view_all');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->canAny($user, 'leads.view', 'leads.view_all')
            && ($this->can($user, 'leads.view_all') || $this->inBranchScope($user, $lead->branchId));
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->can($user, 'leads.edit')
            && $this->inBranchScope($user, $lead->branchId)
            && $lead->isEditable();
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->can($user, 'leads.delete')
            && $this->inBranchScope($user, $lead->branchId)
            && !$lead->isConverted();
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $this->can($user, 'leads.assign') && $this->inBranchScope($user, $lead->branchId);
    }

    public function changeStatus(User $user, Lead $lead): bool
    {
        return $this->can($user, 'leads.edit')
            && $this->inBranchScope($user, $lead->branchId)
            && $lead->isEditable();
    }

    public function addNote(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead) && $this->can($user, 'leads.edit');
    }

    public function convert(User $user, Lead $lead): bool
    {
        return $this->can($user, 'leads.convert')
            && $this->inBranchScope($user, $lead->branchId)
            && $lead->isEditable()
            && !$lead->statusIsWon;
    }

    public function import(User $user): bool
    {
        return $this->can($user, 'leads.import') && $this->can($user, 'imports.run');
    }

    public function export(User $user): bool
    {
        return $this->can($user, 'leads.export') && $this->can($user, 'exports.run');
    }

    public function merge(User $user, ?Lead $survivor = null): bool
    {
        if (!$this->can($user, 'leads.merge')) {
            return false;
        }

        return $survivor === null
            || ($this->inBranchScope($user, $survivor->branchId) && $survivor->isEditable());
    }
}
