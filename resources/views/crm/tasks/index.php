<?php
/**
 * @var list<array<string,mixed>> $rows @var int $total @var array<int,string> $links @var array<string,int> $counts @var bool $seeAll
 * @var array{tab:string,priority:string,related:string,assignee:int,q:string} $filters @var int $page @var int $perPage
 * @var list<array{id:int,name:string,branch_id:int}> $people @var int $meId
 */
$this->layout('layouts.app', ['title' => 'Tasks', 'currentPath' => '/tasks']);
$this->start('content');

$today = gmdate('Y-m-d');
$tabs = ['open' => 'Open', 'overdue' => 'Overdue', 'today' => 'Due today', 'done' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'];
$query = array_filter($filters, static fn ($v, $k): bool => $v !== '' && $v !== 0 && !($k === 'tab' && $v === 'open'), ARRAY_FILTER_USE_BOTH);
$back = '/tasks' . ($query !== [] || $page > 1 ? '?' . http_build_query($query + ($page > 1 ? ['page' => $page] : [])) : '');
$prio = ['urgent' => 'red', 'high' => 'amber', 'medium' => 'blue', 'low' => 'slate'];
$typeLabel = static fn (string $t): string => ucwords(str_replace('_', ' ', $t));
$post = static fn (string $action, string $label, string $cls, string $back, string $confirm = ''): string
    => '<form method="post" action="' . $action . '" class="inline">' . csrf_field() . '<input type="hidden" name="back" value="' . e_attr($back) . '">'
        . '<button type="submit" class="btn ' . $cls . ' btn-sm"' . ($confirm !== '' ? ' data-confirm="' . e_attr($confirm) . '"' : '') . '>' . e($label) . '</button></form>';
?>
<?= component('page-header', [
    'title' => 'Tasks',
    'subtitle' => $seeAll ? 'Everything in your branches' : 'Tasks assigned to you or created by you',
    'actions' => can('tasks.create') ? '<a href="/tasks/create" class="btn btn-primary btn-sm">New task</a>' : '',
]) ?>

<nav class="mb-4 flex flex-wrap gap-2" aria-label="Task views">
    <?php foreach ($tabs as $key => $label):
        $active = $filters['tab'] === $key;
        $n = $counts[$key] ?? 0;
        ?>
        <a href="/tasks<?= $key === 'open' ? '' : '?tab=' . e_attr($key) ?>" class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-secondary' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
            <?= e($label) ?> <span class="<?= $key === 'overdue' && $n > 0 && !$active ? 'font-semibold text-red-600' : 'opacity-75' ?>"><?= number_format($n) ?></span>
        </a>
    <?php endforeach ?>
</nav>

<form method="get" action="/tasks" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <input type="hidden" name="tab" value="<?= e_attr($filters['tab']) ?>">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($filters['q']) ?>" maxlength="80" placeholder="Task title">
    </div>
    <div>
        <label class="form-label" for="priority">Priority</label>
        <select class="form-select" id="priority" name="priority">
            <option value="">Any</option>
            <?php foreach (['urgent', 'high', 'medium', 'low'] as $p): ?><option value="<?= $p ?>" <?= $filters['priority'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="related">About</label>
        <select class="form-select" id="related" name="related">
            <option value="">Anything</option>
            <?php foreach (['none' => 'No record (standalone)', 'lead' => 'Lead', 'candidate' => 'Candidate', 'application' => 'Application', 'invoice' => 'Invoice', 'payment' => 'Payment', 'visa' => 'Visa', 'travel' => 'Travel', 'employer' => 'Employer', 'tour_booking' => 'Tour booking'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $filters['related'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <?php if ($seeAll): ?>
        <div>
            <label class="form-label" for="assignee">Assigned to</label>
            <select class="form-select" id="assignee" name="assignee">
                <option value="">Anyone</option>
                <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $filters['assignee'] === $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= $p['id'] === $meId ? ' (me)' : '' ?></option><?php endforeach ?>
            </select>
        </div>
    <?php endif ?>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="/tasks?tab=<?= e_attr($filters['tab']) ?>" class="btn btn-ghost">Clear</a>
    </div>
</form>

<?php if ($rows === []): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $filters['tab'] === 'open' ? 'Nothing to do — you are all caught up' : 'No tasks here',
        'message' => 'Tasks appear when someone creates one, or when the system needs a follow-up (for example a payment reminder).',
        'action' => can('tasks.create') ? '<a class="btn btn-primary" href="/tasks/create">New task</a>' : '',
    ])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Tasks">
            <thead><tr><th>Task</th><th>Priority</th><th>Due</th><th>Assigned to</th><th>About</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t):
                $pending = $t['status'] === 'pending';
                $overdue = $pending && $t['due_date'] !== null && $t['due_date'] < $today;
                $id = e_attr($t['public_id']);
                ?>
                <tr>
                    <td>
                        <span class="font-medium text-slate-900"><?= e($t['title']) ?></span>
                        <?php if ($t['source'] === 'system'): ?><?= component('badge', ['label' => 'Automatic', 'color' => 'slate']) ?><?php endif ?>
                        <?php if (!$pending): ?><?= component('badge', ['label' => ucfirst((string) $t['status']), 'color' => $t['status'] === 'completed' ? 'green' : 'slate', 'dot' => true]) ?><?php endif ?>
                        <?php if ($t['description']): ?><p class="mt-0.5 max-w-md whitespace-pre-line text-xs text-slate-500"><?= e(mb_strimwidth((string) $t['description'], 0, 200, '…')) ?></p><?php endif ?>
                    </td>
                    <td><?= component('badge', ['label' => ucfirst((string) $t['priority']), 'color' => $prio[$t['priority']] ?? 'slate']) ?></td>
                    <td class="whitespace-nowrap text-sm <?= $overdue ? 'font-medium text-red-600' : 'text-slate-600' ?>">
                        <?php if ($t['due_date']): ?><?= e(date('d M Y', strtotime((string) $t['due_date']))) ?><?= $t['due_time'] ? ' ' . e(substr((string) $t['due_time'], 0, 5)) : '' ?><?= $overdue ? ' · overdue' : '' ?><?php else: ?>—<?php endif ?>
                    </td>
                    <td class="text-sm text-slate-700"><?= e($t['assignee_name'] ?? '—') ?></td>
                    <td class="text-sm">
                        <?php if (isset($links[(int) $t['id']])): ?><a class="text-brand-600" href="<?= e_attr($links[(int) $t['id']]) ?>"><?= e($typeLabel((string) $t['related_type'])) ?></a>
                        <?php elseif ($t['related_type'] !== 'none'): ?><span class="text-slate-500"><?= e($typeLabel((string) $t['related_type'])) ?></span>
                        <?php else: ?><span class="text-slate-400">—</span><?php endif ?>
                    </td>
                    <td class="text-right">
                        <?php if ($pending): ?>
                            <div class="flex flex-wrap items-center justify-end gap-1">
                                <?php if (can('tasks.complete')): ?><?= $post('/tasks/' . $id . '/complete', 'Complete', 'btn-primary', $back) ?><?php endif ?>
                                <?php if (can('tasks.assign')): ?>
                                    <details class="relative">
                                        <summary class="btn btn-secondary btn-sm cursor-pointer">Reassign<span class="sr-only"> <?= e($t['title']) ?></span></summary>
                                        <form method="post" action="/tasks/<?= $id ?>/reassign" class="absolute right-0 z-10 mt-1 flex w-64 items-center gap-1 rounded border border-slate-200 bg-white p-2 shadow" data-once>
                                            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e_attr($back) ?>">
                                            <label class="sr-only" for="ra-<?= $id ?>">Assign to</label>
                                            <select class="form-select" id="ra-<?= $id ?>" name="assigned_to">
                                                <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $p['id'] === (int) $t['assigned_to'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                        </form>
                                    </details>
                                <?php endif ?>
                                <?php if (can('tasks.edit')): ?><?= $post('/tasks/' . $id . '/cancel', 'Cancel', 'btn-ghost', $back, 'Cancel this task? It stays in the history.') ?><?php endif ?>
                            </div>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/tasks', 'query' => $query]) ?>
<?php endif ?>
<?php $this->stop(); ?>
