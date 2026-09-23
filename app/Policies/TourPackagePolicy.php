<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TourPackage;
use App\Models\User;

/** Tour package authorization: permission only — the catalogue is not branch-scoped. */
final class TourPackagePolicy extends Policy
{
    public function view(User $user, TourPackage $package): bool
    {
        return $this->can($user, 'tours.packages.view');
    }

    public function update(User $user, TourPackage $package): bool
    {
        return $this->can($user, 'tours.packages.edit');
    }

    public function delete(User $user, TourPackage $package): bool
    {
        return $this->can($user, 'tours.packages.delete');
    }

    public function publish(User $user, TourPackage $package): bool
    {
        return $this->can($user, 'tours.packages.publish');
    }
}
