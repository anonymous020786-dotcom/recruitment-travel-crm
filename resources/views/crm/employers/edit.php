<?php
/** @var \App\Models\Employer $employer @var array<string,string> $countries @var array $owners */
$this->layout('layouts.app', ['title' => 'Edit ' . $employer->companyName, 'currentPath' => '/employers']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Edit ' . $employer->companyName,
    'subtitle' => $employer->employerNumber,
    'breadcrumbs' => [
        ['label' => 'Employers', 'href' => '/employers'],
        ['label' => $employer->employerNumber, 'href' => '/employers/' . $employer->publicId],
        ['label' => 'Edit'],
    ],
]) ?>

<form method="post" action="/employers/<?= e_attr($employer->publicId) ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">
    <div class="card card-body"><?= $this->partial('crm.employers._form', compact('employer', 'countries', 'owners')) ?></div>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="/employers/<?= e_attr($employer->publicId) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
