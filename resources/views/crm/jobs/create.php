<?php
/** @var array<string,string> $countries @var array<string,string> $employers @var string $selectedEmployer */
$this->layout('layouts.app', ['title' => 'New job', 'currentPath' => '/jobs']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'New job',
    'breadcrumbs' => [['label' => 'Jobs', 'href' => '/jobs'], ['label' => 'New job']],
]) ?>

<form method="post" action="/jobs" data-once>
    <?= csrf_field() ?>
    <div class="card card-body">
        <?= component('field', [
            'name' => 'employer', 'label' => 'Employer', 'control' => 'select', 'required' => true, 'placeholder' => 'Select…',
            'options' => $employers, 'value' => (string) old('employer', $selectedEmployer),
        ]) ?>
        <?= $this->partial('crm.jobs._form', compact('countries')) ?>
    </div>
    <p class="mt-2 text-xs text-slate-500">The job starts as a draft; open it from its page once it is ready.</p>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Create job</button>
        <a href="/jobs" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
