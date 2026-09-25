<?php
/** @var list<array{id:int,name:string,is_active:bool,sort_order:int,leads:int}> $sources @var string $protected */
$this->layout('layouts.app', ['title' => 'Lead sources', 'currentPath' => '/admin/lead-sources']);
$this->start('content');

$manage = can('settings.manage');
$last = count($sources) - 1;
$btn = static fn (string $action, string $fields, string $label, string $cls = 'btn-secondary', string $confirm = '', string $sr = ''): string
    => '<form method="post" action="' . $action . '" class="inline">' . csrf_field() . $fields
        . '<button type="submit" class="btn ' . $cls . ' btn-sm"' . ($confirm !== '' ? ' data-confirm="' . e_attr($confirm) . '"' : '') . '>' . e($label) . ($sr !== '' ? '<span class="sr-only"> ' . e($sr) . '</span>' : '') . '</button></form>';
?>
<?= component('page-header', [
    'title' => 'Lead sources',
    'subtitle' => 'The “where did this lead come from” choices on the lead form, in this order.',
]) ?>

<?php if ($manage): ?>
    <form method="post" action="/admin/lead-sources" class="card card-body mb-4 flex flex-wrap items-end gap-3" data-once>
        <?= csrf_field() ?>
        <div>
            <label class="form-label" for="new-name">Add a source</label>
            <input class="form-input" id="new-name" name="name" maxlength="80" required value="<?= e_attr((string) old('name', '')) ?>" placeholder="e.g. Radio ad" <?= error('name') ? 'aria-invalid="true" aria-describedby="new-name-err"' : '' ?>>
            <?php if (error('name')): ?><p id="new-name-err" class="mt-1 text-xs text-red-600" role="alert"><?= e((string) error('name')) ?></p><?php endif ?>
        </div>
        <button type="submit" class="btn btn-primary">Add</button>
    </form>
<?php endif ?>

<div class="table-wrap">
    <table class="data" aria-label="Lead sources">
        <thead><tr><th>Source</th><th>Leads</th><th>Status</th><?php if ($manage): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
        <tbody>
        <?php foreach ($sources as $i => $s):
            $locked = strcasecmp($s['name'], $protected) === 0;
            ?>
            <tr>
                <td>
                    <?php if ($manage && !$locked): ?>
                        <form method="post" action="/admin/lead-sources/<?= (int) $s['id'] ?>" class="flex items-center gap-2" data-once>
                            <?= csrf_field() ?><input type="hidden" name="_method" value="PUT">
                            <label class="sr-only" for="src-<?= (int) $s['id'] ?>">Name of source <?= e($s['name']) ?></label>
                            <input class="form-input max-w-xs" id="src-<?= (int) $s['id'] ?>" name="name" value="<?= e_attr($s['name']) ?>" maxlength="80" required>
                            <button type="submit" class="btn btn-ghost btn-sm">Rename</button>
                        </form>
                    <?php else: ?>
                        <span class="font-medium text-slate-900"><?= e($s['name']) ?></span>
                        <?php if ($locked): ?><p class="text-xs text-slate-500">Used by the public enquiry inbox — cannot be renamed or switched off.</p><?php endif ?>
                    <?php endif ?>
                </td>
                <td class="text-sm text-slate-600"><a class="text-brand-600 hover:underline" href="/leads?source=<?= (int) $s['id'] ?>"><?= number_format($s['leads']) ?></a></td>
                <td><?= component('badge', ['label' => $s['is_active'] ? 'On' : 'Off', 'color' => $s['is_active'] ? 'green' : 'slate', 'dot' => true]) ?></td>
                <?php if ($manage): ?>
                    <td class="text-right">
                        <?= $i > 0 ? $btn('/admin/lead-sources/' . (int) $s['id'] . '/move', '<input type="hidden" name="direction" value="up">', '↑', 'btn-ghost', '', 'move ' . $s['name'] . ' up') : '' ?>
                        <?= $i < $last ? $btn('/admin/lead-sources/' . (int) $s['id'] . '/move', '<input type="hidden" name="direction" value="down">', '↓', 'btn-ghost', '', 'move ' . $s['name'] . ' down') : '' ?>
                        <?php if (!$locked): ?>
                            <?= $s['is_active']
                                ? $btn('/admin/lead-sources/' . (int) $s['id'] . '/toggle', '<input type="hidden" name="active" value="0">', 'Switch off', 'btn-ghost', 'Stop offering “' . $s['name'] . '” on new leads? Existing leads keep it.')
                                : $btn('/admin/lead-sources/' . (int) $s['id'] . '/toggle', '<input type="hidden" name="active" value="1">', 'Switch on', 'btn-secondary') ?>
                        <?php endif ?>
                    </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php $this->stop(); ?>
