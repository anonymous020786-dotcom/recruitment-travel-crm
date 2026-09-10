<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\LeadFollowupRepository;
use App\Services\LeadService;

/**
 * "My follow-ups" — the personal queue of pending follow-ups, plus the
 * complete / cancel actions (also invoked from a lead's page). Actions are keyed
 * by the follow-up id; the service re-checks branch scope and permissions.
 */
final class FollowupController extends CrmController
{
    public function __construct(
        private readonly LeadFollowupRepository $followups,
        private readonly LeadService $service,
    ) {
    }

    public function index(): Response
    {
        $user = $this->currentUser();
        $scope = $this->scope();

        return view_response('crm.followups.index', [
            'overdue'  => $this->followups->pendingForUser($user->id, $scope, 'overdue', 200),
            'today'    => $this->followups->pendingForUser($user->id, $scope, 'today', 200),
            'upcoming' => $this->followups->pendingForUser($user->id, $scope, 'upcoming', 200),
            'counts'   => $this->followups->countsForUser($user->id, $scope),
        ]);
    }

    public function complete(Request $request, string $id): Response
    {
        $next = null;
        if ($request->filled('next_due_date')) {
            $next = [
                'due_date'    => (string) $request->input('next_due_date', ''),
                'due_time'    => $request->input('next_due_time'),
                'channel'     => (string) $request->input('next_channel', 'call'),
                'subject'     => $request->input('next_subject'),
                'assigned_to' => null,
            ];
        }

        try {
            $this->service->completeFollowup(
                (int) $id,
                $this->currentUser(),
                (string) $request->input('outcome', ''),
                $request->boolean('log_as_note'),
                $next,
            );
            flash('status', 'Follow-up completed.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not complete the follow-up.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return back();
    }

    public function cancel(Request $request, string $id): Response
    {
        try {
            $this->service->cancelFollowup((int) $id, $this->currentUser());
            flash('status', 'Follow-up cancelled.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return back();
    }
}
