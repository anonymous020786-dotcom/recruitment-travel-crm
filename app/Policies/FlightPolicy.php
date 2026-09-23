<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FlightBooking;
use App\Models\User;

/** Flight booking authorization: permission + the candidate's branch. */
final class FlightPolicy extends Policy
{
    public function view(User $user, FlightBooking $flight): bool
    {
        return $this->canInBranch($user, 'travel.view', $flight->branchId);
    }

    public function edit(User $user, FlightBooking $flight): bool
    {
        return $this->canInBranch($user, 'travel.tickets.manage', $flight->branchId);
    }
}
