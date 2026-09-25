<?php
/**
 * @var string $key @var array<string,mixed> $def @var array<string,array{set:bool,source:string,hint:string,value:string,unreadable:bool,updated_at:?string}> $fields
 * @var array{state:string,label:string} $status @var bool $enabled @var bool $canManage
 */
$this->layout('layouts.app', ['title' => $def['label'] . ' — Integrations', 'currentPath' => '/admin/integrations']);
$this->start('content');

$tone = ['configured' => 'green', 'incomplete' => 'amber', 'off' => 'slate', 'empty' => 'slate'];
$sourceLabel = ['saved' => 'Saved here', 'env' => 'From the .env file', 'none' => 'Not set'];
?>
<?php $webhookUrl = $webhookUrl ?? null; $testable = $testable ?? false; ?>
<?= component('page-header', [
    'title' => $def['label'],
    'subtitle' => $def['description'],
    'breadcrumbs' => [['label' => 'Integrations', 'href' => '/admin/integrations'], ['label' => $def['label']]],
    'actions' => $def['docs'] !== '' ? '<a class="btn btn-secondary btn-sm" href="' . e_attr($def['docs']) . '" target="_blank" rel="noopener noreferrer">Provider docs<span class="sr-only"> (opens in a new tab)</span></a>' : '',
]) ?>

<p class="mb-4"><?= component('badge', ['label' => $status['label'], 'color' => $tone[$status['state']] ?? 'slate', 'dot' => true]) ?></p>

<?php if ($webhookUrl !== null): ?>
    <div class="card card-body mb-4 max-w-2xl">
        <p class="text-sm font-medium text-slate-900">Webhook URL</p>
        <label class="sr-only" for="webhook-url">Webhook URL</label>
        <input id="webhook-url" class="form-input mt-1" readonly value="<?= e_attr($webhookUrl) ?>" onfocus="this.select()">
        <p class="mt-1 text-xs text-slate-500">Create a webhook with this address in the provider's dashboard, using the signing secret you save below. Payments are recorded only from signed messages.</p>
    </div>
<?php endif ?>

<form method="post" action="/admin/integrations/<?= e_attr($key) ?>" class="card card-body max-w-2xl" autocomplete="off" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">

    <?php foreach ($def['fields'] as $name => $f):
        $v = $fields[$name];
        $id = 'f-' . $name;
        $type = $f['type'] ?? 'text';
        $err = error($name);
        $describedBy = trim(($f['help'] ?? '' ? $id . '-help ' : '') . ($err ? $id . '-err' : ''));
        $aria = $describedBy !== '' ? ' aria-describedby="' . e_attr($describedBy) . '"' : '';
        ?>
        <div class="mb-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <label class="form-label mb-0" for="<?= e_attr($id) ?>"><?= e($f['label']) ?><?php if (!empty($f['required'])): ?> <span class="text-red-500" aria-hidden="true">*</span><?php endif ?></label>
                <span class="text-xs <?= $v['set'] ? 'text-slate-500' : 'text-amber-600' ?>"><?= e($sourceLabel[$v['source']]) ?><?= $v['updated_at'] && $v['source'] === 'saved' ? ' · ' . e(substr($v['updated_at'], 0, 10)) : '' ?></span>
            </div>

            <?php if ($type === 'secret'): ?>
                <input class="form-input" type="password" id="<?= e_attr($id) ?>" name="f[<?= e_attr($name) ?>]" value="" autocomplete="new-password" spellcheck="false"
                    placeholder="<?= $v['source'] === 'saved' ? e($v['hint'] . ' — leave empty to keep') : ($v['source'] === 'env' ? 'Using the .env value — type to replace' : 'Paste the secret') ?>"<?= $aria ?> <?= $canManage ? '' : 'disabled' ?>>
                <?php if ($v['unreadable']): ?><p class="mt-1 text-xs text-red-600" role="alert">The saved secret can no longer be decrypted (the application key changed). Enter it again.</p><?php endif ?>
                <?php if ($canManage && $v['source'] === 'saved'): ?>
                    <label class="mt-1 inline-flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="clear[]" value="<?= e_attr($name) ?>"> Remove the saved value</label>
                <?php endif ?>
            <?php elseif ($type === 'select'): ?>
                <select class="form-select" id="<?= e_attr($id) ?>" name="f[<?= e_attr($name) ?>]"<?= $aria ?> <?= $canManage ? '' : 'disabled' ?>>
                    <option value="">— <?= $v['source'] === 'env' ? 'use the .env value' : 'not set' ?> —</option>
                    <?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= e_attr((string) $ov) ?>" <?= $v['value'] === (string) $ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach ?>
                </select>
            <?php else: ?>
                <input class="form-input" type="<?= $type === 'number' ? 'number' : 'text' ?>" id="<?= e_attr($id) ?>" name="f[<?= e_attr($name) ?>]" value="<?= e_attr($v['value']) ?>" maxlength="255"
                    autocomplete="off" spellcheck="false"<?= $aria ?> <?= $canManage ? '' : 'disabled' ?>>
            <?php endif ?>

            <?php if (!empty($f['help'])): ?><p id="<?= e_attr($id) ?>-help" class="mt-1 text-xs text-slate-500"><?= e($f['help']) ?></p><?php endif ?>
            <?php if ($err): ?><p id="<?= e_attr($id) ?>-err" class="mt-1 text-xs text-red-600" role="alert"><?= e((string) $err) ?></p><?php endif ?>
        </div>
    <?php endforeach ?>

    <label class="mb-4 inline-flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>> Service switched on
    </label>

    <?php if ($canManage): ?>
        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="/admin/integrations" class="btn btn-ghost">Back</a>
            <button type="submit" form="reset-form" class="btn btn-ghost ml-auto text-red-600" data-confirm="Remove everything saved in the panel for <?= e_attr($def['label']) ?>? The .env values (if any) apply again.">Remove all saved values</button>
        </div>
        <p class="mt-3 text-xs text-slate-500">Secrets are encrypted with the application key before they are stored and are never shown again. Saving asks you to confirm your password.</p>
    <?php endif ?>
</form>
<?php if ($canManage && $testable): ?>
    <form method="post" action="/admin/integrations/<?= e_attr($key) ?>/test" class="mt-3"><?= csrf_field() ?><button type="submit" class="btn btn-secondary">Test connection</button> <span class="text-xs text-slate-500">uses the saved values</span></form>
<?php endif ?>
<?php if ($canManage): ?>
    <form id="reset-form" method="post" action="/admin/integrations/<?= e_attr($key) ?>/reset"><?= csrf_field() ?></form>
<?php endif ?>
<?php $this->stop(); ?>
