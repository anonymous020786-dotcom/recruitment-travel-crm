<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TourBooking;
use App\Models\User;

/** Tour booking authorization: permission + the booking's own branch. */
final class TourBookingPolicy extends Policy
{
    public function view(User $user, TourBooking $booking): bool
    {
        return $this->canInBranch($user, 'tours.bookings.view', $booking->branchId);
    }

    public function edit(User $user, TourBooking $booking): bool
    {
        return $this->canInBranch($user, 'tours.bookings.edit', $booking->branchId);
    }

    public function changeStatus(User $user, TourBooking $booking): bool
    {
        return $this->canInBranch($user, 'tours.bookings.change_status', $booking->branchId);
    }

    /** Cancelling is a status change that additionally needs the (separately granted) cancel permission. */
    public function cancel(User $user, TourBooking $booking): bool
    {
        return $this->can($user, 'tours.bookings.change_status')
            && $this->canInBranch($user, 'tours.bookings.delete', $booking->branchId);
    }
}
