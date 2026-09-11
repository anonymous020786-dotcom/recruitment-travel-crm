<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Candidate;
use App\Models\User;

/**
 * Candidate authorization: permission + branch scope. Registered on the Gate
 * for App\Models\Candidate.
 */
final class CandidatePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, 'candidates.view', 'candidates.view_all');
    }

    public function view(User $user, Candidate $candidate): bool
    {
        return $this->canAny($user, 'candidates.view', 'candidates.view_all')
            && ($this->can($user, 'candidates.view_all') || $this->inBranchScope($user, $candidate->branchId));
    }

    public function update(User $user, Candidate $candidate): bool
    {
        return $this->can($user, 'candidates.edit') && $this->inBranchScope($user, $candidate->branchId);
    }

    public function delete(User $user, Candidate $candidate): bool
    {
        return $this->can($user, 'candidates.delete') && $this->inBranchScope($user, $candidate->branchId);
    }

    public function export(User $user): bool
    {
        return $this->can($user, 'candidates.export') && $this->can($user, 'exports.run');
    }

    public function manageEducation(User $user, Candidate $candidate): bool
    {
        return $this->can($user, 'candidates.education.manage') && $this->inBranchScope($user, $candidate->branchId);
    }

    public function manageExperience(User $user, Candidate $candidate): bool
    {
        return $this->can($user, 'candidates.experience.manage') && $this->inBranchScope($user, $candidate->branchId);
    }

    public function manageSkills(User $user, Candidate $candidate): bool
    {
        return $this->can($user, 'candidates.skills.manage') && $this->inBranchScope($user, $candidate->branchId);
    }

    public function managePreferences(User $user, Candidate $candidate): bool
    {
        return $this->can($user, 'candidates.preferences.manage') && $this->inBranchScope($user, $candidate->branchId);
    }

    public function managePassport(User $user, Candidate $candidate): bool
    {
        return $this->can($user, 'candidates.passport.manage') && $this->inBranchScope($user, $candidate->branchId);
    }
}
