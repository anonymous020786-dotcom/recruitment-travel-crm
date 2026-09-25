<?php
/** @var ?array<string,mixed> $branch */
$editing = $branch !== null;
$this->layout('layouts.app', ['title' => $editing ? 'Edit ' . $branch['name'] : 'Add branch', 'currentPath' => '/admin/branches']);
$this->start('content');

$action = $editing ? '/admin/branches/' . e_attr($branch['public_id']) : '/admin/branches';
$val = static fn (string $k): string => (string) old($k, $editing ? (string) ($branch[$k] ?? '') : '');
?>
<?= component('page-header', [
    'title' => $editing ? 'Edit ' . $branch['name'] : 'Add branch',
    'breadcrumbs' => [['label' => 'Branches', 'href' => '/admin/branches'], ['label' => $editing ? $branch['name'] : 'Add branch']],
]) ?>

<form method="post" action="<?= $action ?>" class="card card-body max-w-2xl" data-once>
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="_method" value="PUT"><?php endif ?>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2"><?= component('field', ['name' => 'name', 'label' => 'Branch name', 'required' => true, 'value' => $val('name'), 'attrs' => 'maxlength="120"']) ?></div>
        <?= component('field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'value' => $val('code'), 'attrs' => 'maxlength="20" style="text-transform:uppercase" placeholder="MUM"', 'hint' => 'Letters, numbers, hyphens']) ?>
    </div>
    <?= component('field', ['name' => 'address_line1', 'label' => 'Address', 'value' => $val('address_line1'), 'attrs' => 'maxlength="180"']) ?>
    <?= component('field', ['name' => 'address_line2', 'label' => 'Address (line 2)', 'value' => $val('address_line2'), 'attrs' => 'maxlength="180"']) ?>
    <div class="grid gap-4 sm:grid-cols-3">
        <?= component('field', ['name' => 'city', 'label' => 'City', 'value' => $val('city'), 'attrs' => 'maxlength="90"']) ?>
        <?= component('field', ['name' => 'state', 'label' => 'State', 'value' => $val('state'), 'attrs' => 'maxlength="90"']) ?>
        <?= component('field', ['name' => 'country', 'label' => 'Country code', 'value' => $val('country'), 'attrs' => 'maxlength="2" placeholder="IN"']) ?>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= component('field', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'value' => $val('phone'), 'attrs' => 'maxlength="30"']) ?>
        <?= component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $val('email'), 'attrs' => 'maxlength="180"']) ?>
    </div>
    <div class="flex gap-2">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create branch' ?></button>
        <a href="/admin/branches" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
