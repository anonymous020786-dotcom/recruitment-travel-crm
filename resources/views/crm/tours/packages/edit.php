<?php
/** @var \App\Models\TourPackage $package */
$this->layout('layouts.app', ['title' => 'Edit ' . $package->name, 'currentPath' => '/tours/packages']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Edit ' . $package->name,
    'breadcrumbs' => [
        ['label' => 'Tour packages', 'href' => '/tours/packages'],
        ['label' => $package->name, 'href' => '/tours/packages/' . $package->publicId],
        ['label' => 'Edit'],
    ],
]) ?>

<form method="post" action="/tours/packages/<?= e_attr($package->publicId) ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">
    <div class="card card-body"><?= $this->partial('crm.tours.packages._form', compact('package')) ?></div>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="/tours/packages/<?= e_attr($package->publicId) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
