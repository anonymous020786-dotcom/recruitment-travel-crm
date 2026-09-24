<?php
/**
 * @var array{id:int,name:string,label:string,description:?string,is_system:bool,permissions:int,users:int} $role
 * @var array<string,list<array{id:int,name:string,label:string}>> $catalogue
 * @var list<string> $granted @var list<string> $defaults @var bool $editable
 */
$this->layout('layouts.app', ['title' => $role['label'] . ' permissions', 'currentPath' => '/admin/roles']);
$this->start('content');

$base = '/admin/roles/' . e_attr($role['name']);
$isSuper = $role['name'] === 'super_admin';
$posted = old('permissions', null);
$current = is_array($posted) ? array_map('strval', $posted) : $granted;
$has = array_flip($current);
$isDefault = array_flip($defaults);
$superOnly = ['roles.manage'];
?>
<?= component('page-header', [
    'title' => $role['label'],
    'subtitle' => number_format($role['users']) . ' ' . ($role['users'] === 1 ? 'person' : 'people') . ' in this role',
    'breadcrumbs' => [['label' => 'Roles', 'href' => '/admin/roles'], ['label' => $role['label']]],
]) ?>

<?php if (error('form')): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => error('form')]) ?></div><?php endif ?>
<?php if (error('permissions')): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => error('permissions')]) ?></div><?php endif ?>
<?php if ($isSuper): ?>
    <div class="mb-4"><?= component('alert', ['type' => 'info', 'message' => 'Super admin always has every permission and cannot be edited.']) ?></div>
<?php endif ?>

<form method="post" action="<?= $base ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($catalogue as $module => $permissions): ?>
        <fieldset class="card card-body">
            <legend class="mb-2 text-sm font-semibold capitalize text-slate-900"><?= e(str_replace('_', ' ', $module)) ?></legend>
            <?php foreach ($permissions as $p):
                $on = $isSuper || isset($has[$p['name']]);
                $id = 'perm-' . preg_replace('/[^a-z0-9]+/i', '-', $p['name']);
                $differs = !$isSuper && $on !== isset($isDefault[$p['name']]);
                $locked = !$editable || in_array($p['name'], $superOnly, true);
                ?>
                <div class="mb-1.5 flex items-start gap-2 text-sm">
                    <input type="checkbox" id="<?= e_attr($id) ?>" name="permissions[]" value="<?= e_attr($p['name']) ?>" class="mt-1"
                        <?= $on ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                    <label for="<?= e_attr($id) ?>" class="text-slate-700">
                        <?= e($p['label']) ?>
                        <span class="block text-xs text-slate-400"><?= e($p['name']) ?><?= $differs ? ' · <span class="text-amber-600">differs from default</span>' : '' ?></span>
                    </label>
                </div>
            <?php endforeach ?>
        </fieldset>
    <?php endforeach ?>
    </div>

    <?php if ($editable): ?>
        <div class="sticky bottom-0 mt-4 flex flex-wrap items-center gap-3 border-t border-slate-200 bg-white/95 py-3">
            <button type="submit" class="btn btn-primary">Save permissions</button>
            <a href="/admin/roles" class="btn btn-ghost">Cancel</a>
            <button type="submit" form="reset-form" class="btn btn-secondary ml-auto" data-confirm="Put <?= e_attr($role['label']) ?> back to the default permissions? Your changes to this role are lost.">Reset to default</button>
        </div>
    <?php endif ?>
</form>
<?php if ($editable): ?>
    <form id="reset-form" method="post" action="<?= $base ?>/reset"><?= csrf_field() ?></form>
<?php endif ?>
<?php $this->stop(); ?>
