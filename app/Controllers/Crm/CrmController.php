<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Auth\BranchScope;
use App\Controllers\Controller;
use App\Models\User;

/**
 * Base for authenticated CRM controllers. Routes in the CRM group always run
 * `auth` + `branch`, so user() and scope() are guaranteed.
 */
abstract class CrmController extends Controller
{
    protected function currentUser(): User
    {
        $user = user();
        if ($user === null) {
            abort(401);
        }

        return $user;
    }

    protected function scope(): BranchScope
    {
        return branch_scope() ?? BranchScope::of([]);
    }
}
