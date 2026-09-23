<?php
/** @var array<string,string> $countries @var array $owners */
$this->layout('layouts.app', ['title' => 'New employer', 'currentPath' => '/employers']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'New employer',
    'breadcrumbs' => [['label' => 'Employers', 'href' => '/employers'], ['label' => 'New employer']],
]) ?>

<form method="post" action="/employers" data-once>
    <?= csrf_field() ?>
    <div class="card card-body"><?= $this->partial('crm.employers._form', compact('countries', 'owners')) ?></div>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Create employer</button>
        <a href="/employers" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
