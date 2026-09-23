<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Job;
use App\Models\User;

/** Job authorization: permission + branch scope of the job's own branch. */
final class JobPolicy extends Policy
{
    public function view(User $user, Job $job): bool
    {
        return $this->can($user, 'jobs.view') && $this->inBranchScope($user, $job->branchId);
    }

    public function update(User $user, Job $job): bool
    {
        return $this->can($user, 'jobs.edit') && $this->inBranchScope($user, $job->branchId);
    }

    public function delete(User $user, Job $job): bool
    {
        return $this->can($user, 'jobs.delete') && $this->inBranchScope($user, $job->branchId);
    }

    public function changeStatus(User $user, Job $job): bool
    {
        return $this->can($user, 'jobs.change_status') && $this->inBranchScope($user, $job->branchId);
    }

    public function publish(User $user, Job $job): bool
    {
        return $this->can($user, 'jobs.publish') && $this->inBranchScope($user, $job->branchId);
    }

    public function match(User $user, Job $job): bool
    {
        return $this->can($user, 'jobs.match') && $this->inBranchScope($user, $job->branchId);
    }
}
