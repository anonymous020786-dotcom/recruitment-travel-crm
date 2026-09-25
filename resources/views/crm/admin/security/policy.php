<?php
/**
 * @var array{roles:list<string>,grace:int,source:string} $twoFactor @var array<string,int> $gaps
 * @var list<array{name:string,label:string}> $roles @var array{threshold:int,minutes:int} $autoBlock @var bool $canManage
 */
$this->layout('layouts.app', ['title' => 'Two-factor & auto-block — Security', 'currentPath' => '/admin/security']);
$this->start('content');

$chosen = old('roles');
$chosen = is_array($chosen) ? $chosen : $twoFactor['roles'];
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'Who must use a second sign-in step, and when a noisy address is shut out automatically.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'policy']) ?>

<form method="post" action="/admin/security/policy" class="max-w-2xl" data-once>
    <?= csrf_field() ?><input type="hidden" name="_method" value="PUT">

    <section class="card card-body mb-4" aria-labelledby="tf-h">
        <h2 id="tf-h" class="text-sm font-semibold text-slate-900">Require two-factor authentication</h2>
        <p class="mt-1 text-xs text-slate-500">People in the chosen roles must set up an authenticator app or passkey. They keep working for the grace period below, then are sent to the set-up page until they finish. <?= $twoFactor['source'] === 'env' ? 'Currently taken from the .env file; saving here overrides it.' : 'Saved here.' ?></p>
        <fieldset class="mt-3">
            <legend class="sr-only">Roles that must use two-factor authentication</legend>
            <div class="grid gap-2 sm:grid-cols-2">
                <?php foreach ($roles as $r): ?>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="roles[]" value="<?= e_attr($r['name']) ?>" <?= in_array($r['name'], $chosen, true) ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                        <?= e($r['label']) ?>
                        <?php if (($gaps[$r['name']] ?? 0) > 0): ?><span class="text-xs text-amber-700">(<?= (int) $gaps[$r['name']] ?> not set up)</span><?php endif ?>
                    </label>
                <?php endforeach ?>
            </div>
            <?php if (($e = error('roles')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
        </fieldset>
        <div class="mt-3">
            <label class="form-label" for="grace">Sign-ins allowed before it is enforced</label>
            <input id="grace" class="form-input w-28" type="number" inputmode="numeric" min="0" max="30" name="grace" value="<?= e_attr((string) old('grace', (string) $twoFactor['grace'])) ?>" <?= $canManage ? '' : 'disabled' ?>>
            <?php if (($e = error('grace')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
        </div>
    </section>

    <section class="card card-body mb-4" aria-labelledby="ab-h">
        <h2 id="ab-h" class="text-sm font-semibold text-slate-900">Automatic block after failed sign-ins</h2>
        <p class="mt-1 text-xs text-slate-500">When one address fails to sign in this many times within 15 minutes it is blocked for the time below. An allow rule for the address prevents it. 0 switches it off.</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label class="form-label" for="threshold">Failed sign-ins (0 = off)</label>
                <input id="threshold" class="form-input" type="number" inputmode="numeric" min="0" max="1000" name="threshold" value="<?= e_attr((string) old('threshold', (string) $autoBlock['threshold'])) ?>" <?= $canManage ? '' : 'disabled' ?>>
                <?php if (($e = error('threshold')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
            </div>
            <div>
                <label class="form-label" for="minutes">Blocked for (minutes)</label>
                <input id="minutes" class="form-input" type="number" inputmode="numeric" min="5" max="10080" name="minutes" value="<?= e_attr((string) old('minutes', (string) $autoBlock['minutes'])) ?>" <?= $canManage ? '' : 'disabled' ?>>
                <?php if (($e = error('minutes')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
            </div>
        </div>
    </section>

    <?php if ($canManage): ?>
        <button type="submit" class="btn btn-primary">Save</button>
        <p class="mt-2 text-xs text-slate-500">Saving asks you to confirm your password.</p>
    <?php endif ?>
</form>
<?php $this->stop(); ?>
