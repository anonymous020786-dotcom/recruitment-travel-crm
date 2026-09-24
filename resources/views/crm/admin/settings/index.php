<?php
/**
 * @var array<string,array{label:string,help:string}> $groups @var array<string,array<string,mixed>> $fields
 * @var array<string,string> $values @var array<string,string> $defaults @var bool $canManage
 */
$this->layout('layouts.app', ['title' => 'Settings', 'currentPath' => '/admin/settings']);
$this->start('content');

$old = old('s', null);
?>
<?= component('page-header', ['title' => 'Settings', 'subtitle' => 'Business-facing options. Security and money controls live in the environment file and are not editable here.']) ?>

<form method="post" action="/admin/settings" class="max-w-2xl space-y-6" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">

    <?php foreach ($groups as $gKey => $group): ?>
        <fieldset class="card card-body">
            <legend class="mb-1 text-base font-semibold text-slate-900"><?= e($group['label']) ?></legend>
            <p class="mb-4 text-sm text-slate-600"><?= e($group['help']) ?></p>
            <?php foreach ($fields as $key => $f): if ($f['group'] !== $gKey) { continue; }
                $id = 'set-' . preg_replace('/[^a-z0-9]+/i', '-', $key);
                $name = 's[' . $key . ']';
                $value = is_array($old) ? (string) ($old[$key] ?? '') : $values[$key];
                $ph = $defaults[$key] !== '' ? 'Default: ' . $defaults[$key] : '';
                $help = trim((string) ($f['help'] ?? ''));
                $describedBy = trim(($help !== '' ? $id . '-help' : '') . (error($key) ? ' ' . $id . '-err' : ''));
                $aria = $describedBy !== '' ? 'aria-describedby="' . e_attr($describedBy) . '"' : '';
                $type = match ($f['type']) { 'email' => 'email', 'phone' => 'tel', 'int' => 'number', default => 'text' };
                ?>
                <div class="mb-4">
                    <label class="form-label" for="<?= e_attr($id) ?>"><?= e($f['label']) ?></label>
                    <?php if ($f['type'] === 'text'): ?>
                        <textarea class="form-input" id="<?= e_attr($id) ?>" name="<?= e_attr($name) ?>" rows="3" maxlength="<?= (int) $f['max'] ?>" placeholder="<?= e_attr($ph) ?>" <?= $aria ?> <?= $canManage ? '' : 'disabled' ?>><?= e($value) ?></textarea>
                    <?php else: ?>
                        <input class="form-input" id="<?= e_attr($id) ?>" name="<?= e_attr($name) ?>" value="<?= e_attr($value) ?>" placeholder="<?= e_attr($ph) ?>" type="<?= $type ?>"
                            <?= $f['type'] === 'int' ? 'min="' . (int) ($f['min'] ?? 0) . '" max="' . (int) $f['max'] . '"' : 'maxlength="' . (int) $f['max'] . '"' ?> <?= $aria ?> <?= $canManage ? '' : 'disabled' ?>>
                    <?php endif ?>
                    <?php if ($help !== ''): ?><p id="<?= e_attr($id) ?>-help" class="mt-1 text-xs text-slate-500"><?= e($help) ?></p><?php endif ?>
                    <?php if (error($key)): ?><p id="<?= e_attr($id) ?>-err" class="mt-1 text-xs text-red-600" role="alert"><?= e((string) error($key)) ?></p><?php endif ?>
                </div>
            <?php endforeach ?>
        </fieldset>
    <?php endforeach ?>

    <?php if ($canManage): ?>
        <div class="sticky bottom-0 flex items-center gap-3 border-t border-slate-200 bg-white/95 py-3">
            <button type="submit" class="btn btn-primary">Save settings</button>
            <p class="text-xs text-slate-500">Empty a field to go back to its default.</p>
        </div>
    <?php endif ?>
</form>
<?php $this->stop(); ?>
