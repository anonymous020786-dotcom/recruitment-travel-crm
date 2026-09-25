<?php
/** @var list<array<string,mixed>> $branches */
$this->layout('layouts.app', ['title' => 'Branches', 'currentPath' => '/admin/branches']);
$this->start('content');

$manage = can('branches.manage');
$post = static fn (string $action, string $label, string $cls, string $confirm = ''): string
    => '<form method="post" action="' . $action . '" class="inline">' . csrf_field()
        . '<button type="submit" class="btn ' . $cls . ' btn-sm"' . ($confirm !== '' ? ' data-confirm="' . e_attr($confirm) . '"' : '') . '>' . e($label) . '</button></form>';
?>
<?= component('page-header', [
    'title' => 'Branches',
    'subtitle' => count(array_filter($branches, static fn (array $b): bool => (bool) $b['is_active'])) . ' active of ' . count($branches),
    'actions' => $manage ? '<a href="/admin/branches/create" class="btn btn-primary btn-sm">Add branch</a>' : '',
]) ?>

<div class="table-wrap">
    <table class="data" aria-label="Branches">
        <thead><tr><th>Branch</th><th>Location</th><th>Contact</th><th>People</th><th>Leads</th><th>Status</th><?php if ($manage): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
        <tbody>
        <?php foreach ($branches as $b): $id = e_attr($b['public_id']); ?>
            <tr>
                <td>
                    <?php if ($manage): ?><a class="font-medium text-slate-900" href="/admin/branches/<?= $id ?>/edit"><?= e($b['name']) ?></a><?php else: ?><span class="font-medium text-slate-900"><?= e($b['name']) ?></span><?php endif ?>
                    <p class="text-xs text-slate-500"><?= e($b['code']) ?></p>
                </td>
                <td class="text-sm text-slate-600"><?= e(implode(', ', array_filter([(string) $b['city'], (string) $b['state'], (string) $b['country']]))) ?: '—' ?></td>
                <td class="text-sm text-slate-600"><?= e((string) ($b['phone'] ?? '')) ?><?= $b['email'] ? '<p class="text-xs">' . e((string) $b['email']) . '</p>' : '' ?></td>
                <td class="text-sm"><a class="text-brand-600 hover:underline" href="/admin/users"><?= number_format((int) $b['people']) ?></a></td>
                <td class="text-sm text-slate-600"><?= number_format((int) $b['leads']) ?></td>
                <td><?= component('badge', ['label' => (bool) $b['is_active'] ? 'Active' : 'Inactive', 'color' => (bool) $b['is_active'] ? 'green' : 'slate', 'dot' => true]) ?></td>
                <?php if ($manage): ?>
                    <td class="text-right">
                        <a class="btn btn-secondary btn-sm" href="/admin/branches/<?= $id ?>/edit">Edit<span class="sr-only"> <?= e($b['name']) ?></span></a>
                        <?= (bool) $b['is_active']
                            ? $post('/admin/branches/' . $id . '/deactivate', 'Deactivate', 'btn-ghost', 'Deactivate ' . $b['name'] . '? It disappears from every “choose a branch” list; its records are kept.')
                            : $post('/admin/branches/' . $id . '/reactivate', 'Reactivate', 'btn-secondary') ?>
                    </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php $this->stop(); ?>
