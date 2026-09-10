<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Lead;
use App\Repositories\ActivityLogRepository;
use App\Repositories\LeadFollowupRepository;
use App\Repositories\LeadRepository;
use App\Services\LeadService;
use App\Support\ListQuery;
use App\Validators\LeadValidator;

final class LeadController extends CrmController
{
    public function __construct(
        private readonly LeadRepository $leads,
        private readonly LeadFollowupRepository $followups,
        private readonly LeadService $service,
        private readonly ActivityLogRepository $activity,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, LeadRepository::SORT, LeadRepository::FILTER_KEYS, 'created_at');
        $page = $this->leads->paginate($query, $this->scope());

        return view_response('crm.leads.index', [
            'page'      => $page,
            'query'     => $query,
            'statuses'  => $this->leads->statusOptions(),
            'sources'   => $this->leads->sourceOptions(),
            'assignees' => $this->leads->assignableUsers($this->scope()),
            'counts'    => $this->leads->statusCounts($this->scope()),
        ]);
    }

    public function create(Request $request): Response
    {
        return view_response('crm.leads.create', $this->formData() + [
            'duplicates' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $data = (new LeadValidator())->validate($request->only($this->fieldKeys()), 'create');
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/leads/create');
        }

        $branchId = $this->resolveBranch($request);
        $confirmed = $request->boolean('confirm_not_duplicate');

        try {
            $lead = $this->service->create($data, $this->currentUser(), $branchId, $confirmed);
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/leads/create');
        } catch (DomainRuleException $e) {
            if ($e->ruleCode() === DomainRuleException::DUPLICATE_LEAD) {
                session()?->flash('_errors', ['form' => [$e->getMessage()]]);
                session()?->flash('_old_input', array_diff_key($request->all(), ['_token' => 1]));
                session()?->flash('_duplicates', $e->context()['duplicates'] ?? []);

                return Response::redirect('/leads/create');
            }
            throw $e;
        }

        flash('status', "Lead {$lead->leadNumber} created.");

        return Response::redirect('/leads/' . $lead->publicId);
    }

    public function show(Request $request, string $lead): Response
    {
        $model = $this->find($lead);
        authorize('view', $model);

        $notes = $this->leads->notes($model->id);
        $logs = $this->activity->forRecord('lead', $model->id, 100);

        return view_response('crm.leads.show', [
            'lead'      => $model,
            'timeline'  => $this->buildTimeline($notes, $logs),
            'followups' => $this->followups->forLead($model->id),
            'assignees' => $this->leads->assignableUsers($this->scope()),
            'nextStatuses' => $this->nextStatuses($model),
            'canFollowup' => can('followups.create') && $model->isEditable(),
        ]);
    }

    public function edit(Request $request, string $lead): Response
    {
        $model = $this->find($lead);
        authorize('update', $model);

        return view_response('crm.leads.edit', $this->formData() + ['lead' => $model]);
    }

    public function update(Request $request, string $lead): Response
    {
        $model = $this->find($lead);
        authorize('update', $model);

        try {
            $data = (new LeadValidator())->validate($request->only($this->fieldKeys()), 'update');
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/leads/' . $model->publicId . '/edit');
        }

        try {
            $this->service->update($model, $data, $this->currentUser(), (int) $request->input('record_version', $model->recordVersion));
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/leads/' . $model->publicId . '/edit');
        }

        flash('status', 'Lead updated.');

        return Response::redirect('/leads/' . $model->publicId);
    }

    public function destroy(Request $request, string $lead): Response
    {
        $model = $this->find($lead);
        $this->service->delete($model, $this->currentUser(), (int) $request->input('record_version', $model->recordVersion));

        flash('status', "Lead {$model->leadNumber} deleted.");

        return Response::redirect('/leads');
    }

    public function assign(Request $request, string $lead): Response
    {
        $model = $this->find($lead);
        $assignee = $request->input('assigned_to');
        $assigneeId = ($assignee === null || $assignee === '' || $assignee === '0') ? null : (int) $assignee;

        $this->service->assign($model, $assigneeId, $this->currentUser(), (int) $request->input('record_version', $model->recordVersion));

        flash('status', $assigneeId === null ? 'Lead unassigned.' : 'Lead reassigned.');

        return Response::redirect('/leads/' . $model->publicId);
    }

    public function bulkAssign(Request $request): Response
    {
        authorize('leads.assign');

        $ids = array_values(array_filter((array) $request->input('lead_ids', []), 'is_string'));
        $assignee = $request->input('assigned_to');
        $assigneeId = ($assignee === null || $assignee === '' || $assignee === '0') ? null : (int) $assignee;

        $n = $this->service->bulkAssign($ids, $assigneeId, $this->currentUser());

        flash('status', "{$n} lead(s) reassigned.");

        return back();
    }

    public function changeStatus(Request $request, string $lead): Response
    {
        $model = $this->find($lead);

        try {
            $this->service->changeStatus(
                $model,
                (string) $request->input('status', ''),
                $this->currentUser(),
                (int) $request->input('record_version', $model->recordVersion),
                $request->input('reason') !== null ? (string) $request->input('reason') : null,
            );
        } catch (DomainRuleException | ValidationException $e) {
            $message = $e instanceof ValidationException ? ($e->first() ?? 'Could not change status.') : $e->getMessage();
            session()?->flash('error_toast', $message);

            return Response::redirect('/leads/' . $model->publicId);
        }

        flash('status', 'Status updated.');

        return Response::redirect('/leads/' . $model->publicId);
    }

    public function addNote(Request $request, string $lead): Response
    {
        $model = $this->find($lead);

        try {
            $this->service->addNote($model, (string) $request->input('body', ''), $this->currentUser());
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add note.');
        }

        return Response::redirect('/leads/' . $model->publicId . '#timeline');
    }

    public function scheduleFollowup(Request $request, string $lead): Response
    {
        $model = $this->find($lead);

        try {
            $this->service->scheduleFollowup($model, [
                'due_date'    => (string) $request->input('due_date', ''),
                'due_time'    => $request->input('due_time'),
                'channel'     => (string) $request->input('channel', 'call'),
                'subject'     => $request->input('subject'),
                'assigned_to' => $request->input('assigned_to'),
            ], $this->currentUser());
            flash('status', 'Follow-up scheduled.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not schedule the follow-up.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/leads/' . $model->publicId . '#followups');
    }

    // ---- internals -------------------------------------------------

    private function find(string $publicId): Lead
    {
        $lead = $this->leads->findByPublicId($publicId, $this->scope());
        if ($lead === null) {
            abort(404, 'Lead not found.');
        }

        return $lead;
    }

    /** @return list<string> */
    private function fieldKeys(): array
    {
        return [
            'name', 'phone', 'alternate_phone', 'email', 'gender', 'date_of_birth', 'city', 'state',
            'source_id', 'campaign', 'interested_country', 'interested_job', 'experience_years',
            'qualification', 'salary_expectation', 'salary_currency', 'priority', 'assigned_to', 'notes',
        ];
    }

    /** @return array<string,mixed> */
    private function formData(): array
    {
        return [
            'sources'    => $this->leads->sourceOptions(),
            'assignees'  => $this->leads->assignableUsers($this->scope()),
            'countries'  => $this->countryOptions(),
        ];
    }

    private function resolveBranch(Request $request): ?int
    {
        $submitted = $request->input('branch_id');
        if ($submitted !== null && ctype_digit((string) $submitted)) {
            return (int) $submitted;
        }

        return $this->currentUser()->primaryBranchId;
    }

    /** @return array<string,string> */
    private function countryOptions(): array
    {
        $rows = app(\App\Support\Db::class)->select(
            'SELECT code, name FROM countries WHERE is_active = 1 ORDER BY is_gcc DESC, name',
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['code']] = (string) $r['name'];
        }

        return $out;
    }

    /** @return list<string> reachable status keys for the quick-action buttons */
    private function nextStatuses(Lead $lead): array
    {
        /** @var \App\Domain\StatusMachine $machine */
        $machine = app(\App\Domain\StatusMachine::class);
        $next = $machine->transitionsFrom('lead', $lead->statusKey);

        return array_values(array_filter($next, static fn ($s) => $s !== 'converted'));
    }

    /**
     * @param list<array<string,mixed>> $notes
     * @param list<array<string,mixed>> $logs
     * @return list<array{type:string,at:string,actor:?string,text:string}>
     */
    private function buildTimeline(array $notes, array $logs): array
    {
        $items = [];

        foreach ($notes as $n) {
            $items[] = [
                'type' => 'note',
                'at' => (string) $n['created_at'],
                'actor' => $n['user_name'] ?? null,
                'text' => (string) $n['body'],
            ];
        }

        $labels = [
            'created' => 'created the lead',
            'updated' => 'updated the lead',
            'assigned' => 'changed the assignee',
            'status_changed' => 'changed the status',
            'note_added' => 'added a note',
            'deleted' => 'deleted the lead',
        ];

        foreach ($logs as $l) {
            $action = (string) $l['action'];
            if ($action === 'note_added') {
                continue; // already shown as the note itself
            }
            $text = $labels[$action] ?? $action;
            if ($action === 'status_changed') {
                $old = json_decode((string) ($l['old_values'] ?? '{}'), true)['status'] ?? '?';
                $new = json_decode((string) ($l['new_values'] ?? '{}'), true)['status'] ?? '?';
                $text = "changed status: {$old} → {$new}";
                if (!empty($l['context'])) {
                    $text .= ' (' . $l['context'] . ')';
                }
            }
            $items[] = [
                'type' => 'event',
                'at' => (string) $l['created_at'],
                'actor' => null,
                'text' => $text,
                'user_id' => $l['user_id'] ?? null,
            ];
        }

        usort($items, static fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $items;
    }
}
