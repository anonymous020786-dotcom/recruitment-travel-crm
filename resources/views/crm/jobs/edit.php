<?php
/** @var \App\Models\Job $job @var array<string,string> $countries */
$this->layout('layouts.app', ['title' => 'Edit ' . $job->title, 'currentPath' => '/jobs']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Edit ' . $job->title,
    'subtitle' => $job->jobNumber,
    'breadcrumbs' => [
        ['label' => 'Jobs', 'href' => '/jobs'],
        ['label' => $job->jobNumber, 'href' => '/jobs/' . $job->publicId],
        ['label' => 'Edit'],
    ],
]) ?>

<form method="post" action="/jobs/<?= e_attr($job->publicId) ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">
    <div class="card card-body"><?= $this->partial('crm.jobs._form', compact('job', 'countries')) ?></div>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="/jobs/<?= e_attr($job->publicId) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
