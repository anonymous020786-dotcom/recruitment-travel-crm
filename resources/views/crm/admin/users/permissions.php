<?php
/**
 * @var array<string,mixed> $user @var list<array<string,mixed>> $overrides
 * @var array<string,list<array{id:int,name:string,label:string}>> $catalogue @var list<string> $roleNames @var int $effective @var bool $editable
 */
$this->layout('layouts.app', ['title' => 'Permissions — ' . $user['name'], 'currentPath' => '/admin/users']);
$this->start('content');

$base = '/admin/users/' . e_attr((string) $user['public_id']);
$today = gmdate('Y-m-d');
$max = gmdate('Y-m-d', strtotime('+730 days'));
$oldPerm = (string) old('permission', '');
?>
<?= component('page-header', [
    'title' => 'Permissions — ' . $user['name'],
    'subtitle' => 'Give or take away single permissions for this person, on top of their role.',
    'breadcrumbs' => [['label' => 'Users', 'href' => '/admin/users'], ['label' => $user['name'], 'href' => '/admin/users/' . $user['public_id']], ['label' => 'Permissions']],
]) ?>

<?php if (($e = error('form')) !== null): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $e]) ?></div><?php endif ?>

<div class="mb-4 card card-body max-w-3xl text-sm text-slate-700">
    <p><strong><?= e((string) $user['role_label']) ?></strong> role · <?= count($roleNames) ?> permission(s) from the role · <strong><?= (int) $effective ?></strong> in effect for <?= e((string) $user['name']) ?> right now.</p>
    <p class="mt-1 text-xs text-slate-500">A <em>deny</em> beats everything; an <em>allow</em> adds to the role. Changes apply at the person’s next click. To change what a whole role can do, use <a class="text-brand-600" href="/admin/roles/<?= e_attr((string) $user['role_name']) ?>">Roles</a>.</p>
</div>

<?php if (!$editable): ?>
    <div class="mb-4"><?= component('alert', ['type' => 'info', 'message' => $user['role_name'] === 'super_admin' ? 'A super admin already holds every permission.' : 'Only a super admin can change individual permissions, and not their own.']) ?></div>
<?php endif ?>

<section class="mb-6" aria-labelledby="ov-h">
    <h2 id="ov-h" class="mb-2 text-sm font-semibold text-slate-900">Overrides (<?= count($overrides) ?>)</h2>
    <?php if ($overrides === []): ?>
        <div class="card card-body max-w-3xl text-sm text-slate-600">None — <?= e((string) $user['name']) ?> has exactly what the role gives.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data" aria-label="Permission overrides">
                <thead><tr><th>Permission</th><th>Effect</th><th>Until</th><th>Note</th><th>Set by</th><?php if ($editable): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
                <tbody>
                <?php foreach ($overrides as $o): $expired = (int) $o['expired'] === 1; ?>
                    <tr<?= $expired ? ' class="opacity-60"' : '' ?>>
                        <td><span class="font-medium text-slate-900"><?= e((string) $o['label']) ?></span><p class="font-mono text-xs text-slate-500"><?= e((string) $o['name']) ?></p></td>
                        <td><?= component('badge', ['label' => $o['effect'] === 'allow' ? 'Allow' : 'Deny', 'color' => $o['effect'] === 'allow' ? 'green' : 'red', 'dot' => true]) ?></td>
                        <td class="text-xs text-slate-600"><?= $o['expires_at'] === null ? 'until removed' : e(substr((string) $o['expires_at'], 0, 10)) . ($expired ? ' (ended)' : '') ?></td>
                        <td class="text-sm text-slate-600"><?= e((string) ($o['note'] ?? '')) ?></td>
                        <td class="text-xs text-slate-500"><?= e((string) ($o['granted_by_name'] ?? '—')) ?><br><?= e(substr((string) $o['created_at'], 0, 10)) ?></td>
                        <?php if ($editable): ?>
                            <td class="text-right"><form method="post" action="<?= $base ?>/permissions/remove"><?= csrf_field() ?><input type="hidden" name="permission" value="<?= e_attr((string) $o['name']) ?>"><button type="submit" class="btn btn-ghost btn-sm text-red-600">Remove<span class="sr-only"> <?= e((string) $o['name']) ?></span></button></form></td>
                        <?php endif ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($editable): ?>
            <form method="post" action="<?= $base ?>/permissions/reset" class="mt-3" data-confirm="Remove every override for this person?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm text-red-600">Remove all overrides</button></form>
        <?php endif ?>
    <?php endif ?>
</section>

<?php if ($editable): ?>
    <form method="post" action="<?= $base ?>/permissions" class="card card-body max-w-3xl" data-once>
        <?= csrf_field() ?>
        <h2 class="mb-3 text-sm font-semibold text-slate-900">Add or change an override</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="form-label" for="ov-permission">Permission</label>
                <select id="ov-permission" class="form-select" name="permission" required>
                    <option value="">— choose —</option>
                    <?php foreach ($catalogue as $module => $items): ?>
                        <optgroup label="<?= e_attr(ucfirst((string) $module)) ?>">
                            <?php foreach ($items as $p): ?>
                                <option value="<?= e_attr($p['name']) ?>" <?= $oldPerm === $p['name'] ? 'selected' : '' ?>><?= e($p['label']) ?> — <?= e($p['name']) ?><?= in_array($p['name'], $roleNames, true) ? ' (role has it)' : '' ?></option>
                            <?php endforeach ?>
                        </optgroup>
                    <?php endforeach ?>
                </select>
                <?php if (($e = error('permission')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
            </div>
            <div>
                <label class="form-label" for="ov-effect">Effect</label>
                <select id="ov-effect" class="form-select" name="effect">
                    <option value="allow" <?= old('effect', 'allow') === 'allow' ? 'selected' : '' ?>>Allow (adds to the role)</option>
                    <option value="deny" <?= old('effect') === 'deny' ? 'selected' : '' ?>>Deny (takes away from the role)</option>
                </select>
                <?php if (($e = error('effect')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
            </div>
            <div>
                <label class="form-label" for="ov-expires">Last day (optional)</label>
                <input id="ov-expires" class="form-input" type="date" name="expires_on" min="<?= e_attr($today) ?>" max="<?= e_attr($max) ?>" value="<?= e_attr((string) old('expires_on', '')) ?>">
                <?php if (($e = error('expires_on')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
            </div>
            <div class="sm:col-span-2">
                <label class="form-label" for="ov-note">Why (optional)</label>
                <input id="ov-note" class="form-input" name="note" maxlength="200" value="<?= e_attr((string) old('note', '')) ?>" placeholder="e.g. covering for Priya until Friday">
                <?php if (($e = error('note')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
            </div>
        </div>
        <div class="mt-4"><button type="submit" class="btn btn-primary">Save override</button> <span class="text-xs text-slate-500">Asks you to confirm your password.</span></div>
    </form>
<?php endif ?>
<?php $this->stop(); ?>
