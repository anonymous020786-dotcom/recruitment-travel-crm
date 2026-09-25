<?php
/** @var list<array{id:int,name:string,branch_id:int}> $people @var int $meId */
$this->layout('layouts.app', ['title' => 'New task', 'currentPath' => '/tasks']);
$this->start('content');

$chosen = (int) old('assigned_to', $meId);
$options = [];
foreach ($people as $p) {
    $options[(string) $p['id']] = $p['name'] . ($p['id'] === $meId ? ' (me)' : '');
}
?>
<?= component('page-header', ['title' => 'New task', 'breadcrumbs' => [['label' => 'Tasks', 'href' => '/tasks'], ['label' => 'New task']]]) ?>

<form method="post" action="/tasks" class="card card-body max-w-2xl" data-once>
    <?= csrf_field() ?>
    <?= component('field', ['name' => 'title', 'label' => 'What needs doing?', 'required' => true, 'value' => old('title'), 'attrs' => 'maxlength="200" autofocus']) ?>
    <?= component('field', ['name' => 'description', 'label' => 'Details', 'control' => 'textarea', 'rows' => 3, 'value' => old('description'), 'attrs' => 'maxlength="2000"']) ?>
    <div class="grid gap-4 sm:grid-cols-3">
        <?= component('field', ['name' => 'priority', 'label' => 'Priority', 'control' => 'select', 'options' => ['medium' => 'Medium', 'low' => 'Low', 'high' => 'High', 'urgent' => 'Urgent'], 'value' => old('priority', 'medium')]) ?>
        <?= component('field', ['name' => 'due_date', 'label' => 'Due date', 'type' => 'date', 'value' => old('due_date'), 'attrs' => 'min="' . gmdate('Y-m-d') . '"']) ?>
        <?= component('field', ['name' => 'due_time', 'label' => 'Due time', 'type' => 'time', 'value' => old('due_time')]) ?>
    </div>
    <?= component('field', ['name' => 'assigned_to', 'label' => 'Assign to', 'control' => 'select', 'required' => true, 'options' => $options, 'value' => (string) $chosen]) ?>
    <div class="flex gap-2">
        <button type="submit" class="btn btn-primary">Create task</button>
        <a href="/tasks" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
