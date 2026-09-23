<?php
/**
 * @var array<string,string> $packages @var array<int|string,string> $assignees @var string $selectedPackage
 * @var array<int,string> $branches @var int|null $defaultBranch
 */
$this->layout('layouts.app', ['title' => 'New tour booking', 'currentPath' => '/tours/bookings']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'New tour booking',
    'breadcrumbs' => [['label' => 'Tour bookings', 'href' => '/tours/bookings'], ['label' => 'New booking']],
]) ?>

<form method="post" action="/tours/bookings" data-once>
    <?= csrf_field() ?>
    <div class="card card-body"><?= $this->partial('crm.tours.bookings._form', compact('packages', 'assignees', 'selectedPackage', 'branches', 'defaultBranch')) ?></div>
    <p class="mt-2 text-xs text-slate-500">The booking starts as an inquiry; quote and confirm it from its page.</p>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Create booking</button>
        <a href="/tours/bookings" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
