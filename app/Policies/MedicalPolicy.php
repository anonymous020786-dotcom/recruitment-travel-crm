<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MedicalRecord;
use App\Models\User;

/** Medical record authorization: permission + the candidate's branch. */
final class MedicalPolicy extends Policy
{
    public function view(User $user, MedicalRecord $record): bool
    {
        return $this->canInBranch($user, 'medical.view', $record->branchId);
    }

    public function edit(User $user, MedicalRecord $record): bool
    {
        return $this->canInBranch($user, 'medical.edit', $record->branchId);
    }

    public function delete(User $user, MedicalRecord $record): bool
    {
        return $this->canInBranch($user, 'medical.delete', $record->branchId);
    }
}
