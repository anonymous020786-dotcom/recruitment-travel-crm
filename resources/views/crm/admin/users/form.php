<?php
/**
 * @var ?array<string,mixed> $u @var list<array{id:int,name:string,label:string}> $roles @var list<array{id:int,name:string}> $branches
 * @var bool $actorIsSuper @var bool $actorIsAdmin
 */
$editing = $u !== null;
$this->layout('layouts.app', ['title' => $editing ? 'Edit ' . $u['name'] : 'Add user', 'currentPath' => '/admin/users']);
$this->start('content');

$action = $editing ? '/admin/users/' . e_attr($u['public_id']) : '/admin/users';
$val = static fn (string $k, mixed $default = ''): mixed => old($k, $default);
$selectedBranches = array_map('intval', (array) old('branch_ids', $editing ? $u['branch_ids'] : []));
$orgWide = filter_var(old('is_org_wide', $editing ? (bool) $u['is_org_wide'] : false), FILTER_VALIDATE_BOOLEAN);
$roleId = (int) old('role_id', $editing ? $u['role_id'] : 0);
$primary = (string) old('primary_branch_id', $editing ? ($u['primary_branch_id'] ?? '') : '');
?>
<?= component('page-header', [
    'title' => $editing ? 'Edit ' . $u['name'] : 'Add user',
    'breadcrumbs' => [['label' => 'Users', 'href' => '/admin/users'], ['label' => $editing ? $u['name'] : 'Add user']],
]) ?>

<?php if (error('form')): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => error('form')]) ?></div><?php endif ?>

<form method="post" action="<?= $action ?>" class="card card-body max-w-2xl" data-once>
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="_method" value="PUT"><?php endif ?>

    <?= component('field', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'value' => $val('name', $editing ? $u['name'] : ''), 'autocomplete' => 'off', 'attrs' => 'maxlength="120"']) ?>
    <?php if ($editing): ?>
        <div class="mb-4"><p class="form-label">Email</p><p class="text-sm text-slate-800"><?= e($u['email']) ?></p><p class="text-xs text-slate-500">The email address is the sign-in name and cannot be changed.</p></div>
    <?php else: ?>
        <?= component('field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'value' => $val('email'), 'autocomplete' => 'off', 'attrs' => 'maxlength="180"']) ?>
    <?php endif ?>
    <?= component('field', ['name' => 'phone', 'label' => 'Phone (optional)', 'type' => 'tel', 'value' => $val('phone', $editing ? (string) $u['phone'] : ''), 'autocomplete' => 'off', 'attrs' => 'maxlength="30"']) ?>

    <div class="mb-4">
        <label class="form-label" for="role_id">Role <span class="text-red-500" aria-hidden="true">*</span></label>
        <select class="form-select" id="role_id" name="role_id" required<?= error('role_id') ? ' aria-invalid="true" aria-describedby="role_id-error"' : '' ?>>
            <option value="">Choose a role…</option>
            <?php foreach ($roles as $r): if ($r['name'] === 'super_admin' && !$actorIsSuper && $roleId !== $r['id']) { continue; } ?>
                <option value="<?= (int) $r['id'] ?>" <?= $roleId === $r['id'] ? 'selected' : '' ?>><?= e($r['label']) ?></option>
            <?php endforeach ?>
        </select>
        <?php if (error('role_id')): ?><p class="mt-1 text-xs text-red-600" id="role_id-error"><?= e(error('role_id')) ?></p><?php endif ?>
    </div>

    <fieldset class="mb-4">
        <legend class="form-label">Branches</legend>
        <?php if ($actorIsAdmin): ?>
            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_org_wide" value="1" <?= $orgWide ? 'checked' : '' ?>> Access to every branch (organisation-wide)
            </label>
        <?php elseif ($orgWide): ?>
            <input type="hidden" name="is_org_wide" value="1"><p class="mb-2 text-sm text-slate-600">Organisation-wide access (only an administrator can change this).</p>
        <?php endif ?>
        <div class="grid gap-1 sm:grid-cols-2">
            <?php foreach ($branches as $b): ?>
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="branch_ids[]" value="<?= (int) $b['id'] ?>" <?= in_array($b['id'], $selectedBranches, true) ? 'checked' : '' ?>> <?= e($b['name']) ?></label>
            <?php endforeach ?>
        </div>
        <?php if (error('branch_ids')): ?><p class="mt-1 text-xs text-red-600"><?= e(error('branch_ids')) ?></p><?php endif ?>
    </fieldset>

    <div class="mb-4">
        <label class="form-label" for="primary_branch_id">Primary branch</label>
        <select class="form-select" id="primary_branch_id" name="primary_branch_id">
            <option value="">First selected branch</option>
            <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $primary === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach ?>
        </select>
    </div>

    <?php if (!$editing): ?>
        <p class="mb-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">A temporary password is generated and shown to you once after saving. The person must choose their own password at first sign-in.</p>
    <?php endif ?>

    <div class="flex gap-2">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create account' ?></button>
        <a href="<?= $editing ? '/admin/users/' . e_attr($u['public_id']) : '/admin/users' ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
