<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScopeResolver;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Task;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\TaskRepository;
use App\Support\Db;
use App\Support\Ulid;
use App\Validators\TaskValidator;

/**
 * The task centre: everyone's to-do list in one place (the tasks a record spawns — a candidate's follow-up, a "collect
 * payment" reminder from the cron — and standalone tasks people create here).
 *
 *  - what you can see: tasks inside your branches; and unless you hold `tasks.view_all`, only those assigned to or created by you.
 *    A task you may not see is answered exactly like one that does not exist;
 *  - complete needs `tasks.complete`, cancel `tasks.edit`, reassign `tasks.assign`, create `tasks.create` — checked here as well
 *    as on the routes;
 *  - a task can only be given to an active person in the giver's branches, and it then belongs to that person's branch;
 *  - only pending tasks change; completed and cancelled ones are history;
 *  - every action is audited and a new assignee is told (in-app notification).
 */
final class TaskService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PermissionService $permissions,
        private readonly BranchScopeResolver $scopes,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly Db $db,
    ) {
    }

    public function canSeeAll(User $viewer): bool
    {
        return $this->permissions->userCan($viewer, 'tasks.view_all');
    }

    /**
     * @param array<string,mixed> $input tab, priority, related, assignee, q
     * @return array{tab:string,priority:string,related:string,assignee:int,q:string}
     */
    public function filters(array $input): array
    {
        $tab = (string) ($input['tab'] ?? 'open');

        return [
            'tab' => in_array($tab, TaskRepository::TABS, true) ? $tab : 'open',
            'priority' => in_array((string) ($input['priority'] ?? ''), TaskRepository::PRIORITIES, true) ? (string) $input['priority'] : '',
            'related' => in_array((string) ($input['related'] ?? ''), TaskRepository::RELATED_TYPES, true) ? (string) $input['related'] : '',
            'assignee' => max(0, (int) ($input['assignee'] ?? 0)),
            'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 80),
        ];
    }

    /**
     * @param array{tab:string,priority:string,related:string,assignee:int,q:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,links:array<int,string>,counts:array<string,int>,seeAll:bool}
     */
    public function page(User $viewer, array $filters, int $page, int $perPage = 25): array
    {
        $scope = $this->scopes->resolve($viewer);
        $seeAll = $this->canSeeAll($viewer);
        $result = $this->tasks->page($scope, $viewer->id, $seeAll, $filters, $page, $perPage);

        return $result + [
            'links' => $this->tasks->linksFor($result['rows']),
            'counts' => $this->tasks->tabCounts($scope, $viewer->id, $seeAll),
            'seeAll' => $seeAll,
        ];
    }

    /** @return list<array{id:int,name:string,branch_id:int}> people the viewer may give a task to */
    public function assignees(User $viewer): array
    {
        return $this->tasks->assignable($this->scopes->resolve($viewer));
    }

    /**
     * @param array<string,mixed> $input title, description, priority, due_date, due_time, assigned_to
     * @return string the new task's public id
     * @throws AuthorizationException|ValidationException
     */
    public function create(array $input, User $actor): string
    {
        $this->assertCan($actor, 'tasks.create');
        $data = (new TaskValidator())->validate($input);
        $assignee = $this->assignee((int) $data['assigned_to'], $actor, 'assigned_to');

        $publicId = Ulid::generate();
        $id = $this->db->transaction(function () use ($data, $assignee, $publicId, $actor): int {
            $id = $this->tasks->create($data + [
                'public_id' => $publicId, 'related_type' => 'none', 'related_id' => null, 'branch_id' => $assignee['branch_id'],
                'created_by' => $actor->id, 'source' => 'manual',
            ]);
            $this->audit->log('task_created', 'tasks', 'task', $id, null, ['title' => $data['title'], 'assigned_to' => $assignee['id'], 'due_date' => $data['due_date']], null, $actor);

            return $id;
        });
        $this->tell($assignee['id'], $actor, (string) $data['title'], $data['due_date'], $id);

        return $publicId;
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function complete(string $publicId, User $actor): void
    {
        $this->assertCan($actor, 'tasks.complete');
        $task = $this->pending($publicId, $actor);
        if ($this->tasks->markCompleted($task->id) === 0) {
            throw $this->closed();
        }
        $this->audit->log('task_completed', 'tasks', 'task', $task->id, ['status' => 'pending'], ['status' => 'completed', 'title' => $task->title], null, $actor);
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function cancel(string $publicId, User $actor): void
    {
        $this->assertCan($actor, 'tasks.edit');
        $task = $this->pending($publicId, $actor);
        if ($this->tasks->markCancelled($task->id) === 0) {
            throw $this->closed();
        }
        $this->audit->log('task_cancelled', 'tasks', 'task', $task->id, ['status' => 'pending'], ['status' => 'cancelled', 'title' => $task->title], null, $actor);
    }

    /** @throws AuthorizationException|DomainRuleException|ValidationException */
    public function reassign(string $publicId, int $userId, User $actor): void
    {
        $this->assertCan($actor, 'tasks.assign');
        $task = $this->pending($publicId, $actor);
        $assignee = $this->assignee($userId, $actor, 'assigned_to');
        if ($assignee['id'] === $task->assignedTo) {
            return;
        }
        $this->db->transaction(function () use ($task, $assignee, $actor): void {
            if ($this->tasks->reassign($task->id, $assignee['id'], $assignee['branch_id']) === 0) {
                throw $this->closed();
            }
            $this->audit->log('task_reassigned', 'tasks', 'task', $task->id, ['assigned_to' => $task->assignedTo], ['assigned_to' => $assignee['id'], 'title' => $task->title], null, $actor);
        });
        $this->tell($assignee['id'], $actor, $task->title, $task->dueDate, $task->id);
    }

    // ---- internals -----------------------------------------------------------------------------

    /** @throws AuthorizationException */
    private function assertCan(User $actor, string $permission): void
    {
        if (!$this->permissions->userCan($actor, $permission)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    /** A pending task the actor may see, or a 404 (a hidden task looks like a missing one). @throws DomainRuleException */
    private function pending(string $publicId, User $actor): Task
    {
        $task = $this->tasks->findByPublicId($publicId, $this->scopes->resolve($actor));
        $visible = $task !== null && ($this->canSeeAll($actor) || $task->assignedTo === $actor->id || $task->createdBy === $actor->id);
        if (!$visible) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Task not found.', [], 404);
        }
        if (!$task->isPending()) {
            throw $this->closed();
        }

        return $task;
    }

    private function closed(): DomainRuleException
    {
        return new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That task is already closed.', [], 422);
    }

    /**
     * @return array{id:int,name:string,branch_id:int}
     * @throws ValidationException
     */
    private function assignee(int $userId, User $actor, string $field): array
    {
        foreach ($this->tasks->assignable($this->scopes->resolve($actor)) as $person) {
            if ($person['id'] === $userId) {
                return $person;
            }
        }

        throw new ValidationException([$field => ['Choose an active person in your branches.']]);
    }

    private function tell(int $assigneeId, User $by, string $title, ?string $due, int $taskId): void
    {
        if ($assigneeId === $by->id) {
            return;
        }
        $this->notifications->notify($assigneeId, 'task_assigned', 'New task: ' . mb_substr($title, 0, 120), ($due !== null ? 'Due ' . $due . '. ' : '') . 'Assigned by ' . $by->name . '.', 'task', $taskId);
    }
}
