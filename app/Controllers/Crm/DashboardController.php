<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Http\Response;
use App\Repositories\LeadFollowupRepository;
use App\Services\DashboardService;

/**
 * The CRM landing page: the viewer's own follow-up queue (live) plus a permission-aware,
 * branch-scoped business snapshot (cached briefly, see DashboardService).
 */
final class DashboardController extends CrmController
{
    public function __construct(
        private readonly LeadFollowupRepository $followups,
        private readonly DashboardService $dashboard,
    ) {
    }

    public function index(): Response
    {
        $user = $this->currentUser();
        $scope = $this->scope();

        return view_response('crm.dashboard', [
            'followupCounts' => $this->followups->countsForUser($user->id, $scope),
            'dueFollowups'   => array_merge(
                $this->followups->pendingForUser($user->id, $scope, 'overdue', 25),
                $this->followups->pendingForUser($user->id, $scope, 'today', 25),
            ),
            'snap'           => $this->dashboard->snapshot($user, $scope),
        ]);
    }
}
