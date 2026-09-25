<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Services\TaskService;

/** The task centre: list, create, complete, cancel, reassign. The rules live in TaskService. */
final class TaskController extends CrmController
{
    private const FIELDS = ['title', 'description', 'priority', 'due_date', 'due_time', 'assigned_to'];
    private const PER_PAGE = 25;

    public function __construct(private readonly TaskService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->service->filters($request->only(['tab', 'priority', 'related', 'assignee', 'q']));
        $page = max(1, min((int) $request->query('page', '1'), 500));
        $result = $this->service->page($this->currentUser(), $filters, $page, self::PER_PAGE);

        return view_response('crm.tasks.index', [
            'rows' => $result['rows'], 'total' => $result['total'], 'links' => $result['links'], 'counts' => $result['counts'], 'seeAll' => $result['seeAll'],
            'filters' => $filters, 'page' => $page, 'perPage' => self::PER_PAGE,
            'people' => $this->service->assignees($this->currentUser()),
            'meId' => $this->currentUser()->id,
        ]);
    }

    public function create(): Response
    {
        return view_response('crm.tasks.form', ['people' => $this->service->assignees($this->currentUser()), 'meId' => $this->currentUser()->id]);
    }

    public function store(Request $request): Response
    {
        try {
            $this->service->create($request->only(self::FIELDS), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(self::FIELDS), '/tasks/create');
        }
        flash('status', 'Task created.');

        return Response::redirect('/tasks');
    }

    public function complete(Request $request, string $task): Response
    {
        return $this->act($request, fn () => $this->service->complete($task, $this->currentUser()), 'Task completed.');
    }

    public function cancel(Request $request, string $task): Response
    {
        return $this->act($request, fn () => $this->service->cancel($task, $this->currentUser()), 'Task cancelled.');
    }

    public function reassign(Request $request, string $task): Response
    {
        return $this->act($request, fn () => $this->service->reassign($task, (int) $request->input('assigned_to', 0), $this->currentUser()), 'Task reassigned.');
    }

    // ---- internals -----------------------------------------------------------------------------

    /** @param callable():void $do */
    private function act(Request $request, callable $do, string $success): Response
    {
        try {
            $do();
            flash('status', $success);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (ValidationException $e) {
            session()?->flash('error_toast', implode(' ', array_map(static fn (array $m): string => $m[0], $e->errors())));
        }

        return Response::redirect($this->back((string) $request->input('back', '')));
    }

    /** Back to the list the person was on (same filters), but only ever to /tasks — never an arbitrary address. */
    private function back(string $back): string
    {
        return preg_match('#^/tasks(\?[A-Za-z0-9_=&%.+\-]{0,200})?$#D', $back) === 1 ? $back : '/tasks';
    }
}
