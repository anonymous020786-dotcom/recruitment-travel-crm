<?php
/** @var \App\Models\User $user */
$this->layout('layouts.app', ['title' => 'Profile', 'currentPath' => '/account/profile']);
$this->start('content');
?>
<?= component('page-header', ['title' => 'Profile', 'breadcrumbs' => [['label' => 'Account'], ['label' => 'Profile']]]) ?>

<div class="max-w-lg space-y-2 text-sm mb-4">
    <a href="/account/security" class="text-brand-600">Password &amp; security &rarr;</a>
</div>

<form method="post" action="/account/profile" class="max-w-lg card card-body" data-once>
    <?= csrf_field() ?><input type="hidden" name="_method" value="PUT">
    <?= component('field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => old('name', $user->name), 'attrs' => 'maxlength="120"']) ?>
    <?= component('field', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'value' => old('phone', $userPhone ?? ''), 'attrs' => 'maxlength="30"']) ?>
    <div class="mb-4 text-sm text-slate-500">Email: <span class="text-slate-800"><?= e($user->email) ?></span> · Role: <span class="text-slate-800"><?= e($user->roleName) ?></span></div>
    <button class="btn btn-primary">Save</button>
</form>
<?php $this->stop(); ?>
