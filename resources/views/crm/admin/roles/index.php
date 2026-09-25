<?php
/** @var list<array{id:int,name:string,label:string,description:?string,is_system:bool,permissions:int,users:int}> $roles */
$this->layout('layouts.app', ['title' => 'Roles', 'currentPath' => '/admin/roles']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Roles & permissions',
    'subtitle' => 'Choose what each role can do. Changes apply at the person’s next click.',
]) ?>

<div class="table-wrap">
    <table class="data" aria-label="Roles">
        <thead><tr><th>Role</th><th>Permissions</th><th>People</th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
            <tr>
                <td>
                    <a href="/admin/roles/<?= e_attr($r['name']) ?>" class="font-medium text-slate-900"><?= e($r['label']) ?></a>
                    <?php if ($r['description']): ?><p class="text-xs text-slate-500"><?= e($r['description']) ?></p><?php endif ?>
                </td>
                <td class="text-sm text-slate-700"><?= $r['name'] === 'super_admin' ? 'Everything' : number_format($r['permissions']) ?></td>
                <td class="text-sm text-slate-700"><a class="text-brand-600" href="/admin/users?role=<?= e_attr($r['name']) ?>"><?= number_format($r['users']) ?></a></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php $this->stop(); ?>
