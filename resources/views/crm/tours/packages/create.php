<?php
$this->layout('layouts.app', ['title' => 'New tour package', 'currentPath' => '/tours/packages']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'New tour package',
    'breadcrumbs' => [['label' => 'Tour packages', 'href' => '/tours/packages'], ['label' => 'New package']],
]) ?>

<form method="post" action="/tours/packages" data-once>
    <?= csrf_field() ?>
    <div class="card card-body"><?= $this->partial('crm.tours.packages._form') ?></div>
    <p class="mt-2 text-xs text-slate-500">The package starts as a draft; add its itinerary, then activate it from its page.</p>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Create package</button>
        <a href="/tours/packages" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
