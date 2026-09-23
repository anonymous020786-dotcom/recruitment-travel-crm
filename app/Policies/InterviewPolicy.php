<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Interview;
use App\Models\User;

/** Interview authorization: permission + the branch of the owning application. */
final class InterviewPolicy extends Policy
{
    public function view(User $user, Interview $interview): bool
    {
        return $this->canInBranch($user, 'interviews.view', $interview->branchId);
    }

    /** Confirm or reschedule. */
    public function edit(User $user, Interview $interview): bool
    {
        return $this->canInBranch($user, 'interviews.edit', $interview->branchId);
    }

    public function recordOutcome(User $user, Interview $interview): bool
    {
        return $this->canInBranch($user, 'interviews.record_outcome', $interview->branchId);
    }
}
