<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Http\Response;
use App\Repositories\LeadFollowupRepository;
use App\Repositories\LeadRepository;

/**
 * The CRM landing page. Kept light: a couple of scope-aware counts plus the
 * current user's follow-up queue. Richer analytics land in Phase 10.
 */
final class DashboardController extends CrmController
{
    public function __construct(
        private readonly LeadFollowupRepository $followups,
        private readonly LeadRepository $leads,
    ) {
    }

    public function index(): Response
    {
        $user = $this->currentUser();
        $scope = $this->scope();

        $counts = $this->followups->countsForUser($user->id, $scope);
        $statusCounts = can('leads.view') ? $this->leads->statusCounts($scope) : [];

        return view_response('crm.dashboard', [
            'followupCounts'  => $counts,
            'dueFollowups'    => array_merge(
                $this->followups->pendingForUser($user->id, $scope, 'overdue', 25),
                $this->followups->pendingForUser($user->id, $scope, 'today', 25),
            ),
            'openLeads'       => array_sum(array_filter(
                $statusCounts,
                static fn (int $n, string $key): bool => !in_array($key, ['converted', 'lost', 'not_interested'], true),
                ARRAY_FILTER_USE_BOTH,
            )),
            'showLeads'       => can('leads.view'),
        ]);
    }
}
