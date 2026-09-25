<?php
/**
 * @var list<array{bucket:string,label:string,help:string,by:string,strict:bool,limit:int,window:int,default_limit:int,default_window:int,custom:bool,max_limit:int}> $rows
 * @var bool $canManage
 */
$this->layout('layouts.app', ['title' => 'Rate limits — Security', 'currentPath' => '/admin/security']);
$this->start('content');

$dur = static fn (int $s): string => $s % 3600 === 0 ? ($s / 3600) . ' h' : ($s % 60 === 0 ? ($s / 60) . ' min' : $s . ' s');
$oldRl = old('rl');
$oldRl = is_array($oldRl) ? $oldRl : [];
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'How many times something may be done within a time window before people are asked to wait.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'rate-limits']) ?>

<p class="mb-3 max-w-3xl text-sm text-slate-600">Changes apply from the next request. Limits that guard passwords and codes (marked <strong>protected</strong>) may be tightened freely but raised by at most double their default.</p>

<form method="post" action="/admin/security/rate-limits" data-once>
    <?= csrf_field() ?><input type="hidden" name="_method" value="PUT">
    <div class="table-wrap">
        <table class="data" aria-label="Rate limits">
            <thead><tr><th>What</th><th>Counted per</th><th>Requests</th><th>Within (seconds)</th><th>Default</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $b = $r['bucket']; $lim = $oldRl[$b]['limit'] ?? $r['limit']; $win = $oldRl[$b]['window'] ?? $r['window']; ?>
                <tr>
                    <td>
                        <span class="font-medium text-slate-900"><?= e($r['label']) ?></span>
                        <?= $r['strict'] ? component('badge', ['label' => 'Protected', 'color' => 'blue']) : '' ?>
                        <?= $r['custom'] ? component('badge', ['label' => 'Customised', 'color' => 'amber']) : '' ?>
                        <p class="text-xs text-slate-500"><?= e($r['help']) ?></p>
                        <?php foreach (['limit', 'window'] as $f): if (($e = error($b . '.' . $f)) !== null): ?><p class="text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif; endforeach ?>
                    </td>
                    <td class="text-xs text-slate-600"><?= e($r['by']) ?></td>
                    <td><label class="sr-only" for="rl-<?= e_attr($b) ?>-limit">Requests allowed for <?= e($r['label']) ?></label>
                        <input id="rl-<?= e_attr($b) ?>-limit" class="form-input w-24" type="number" inputmode="numeric" min="1" max="<?= (int) $r['max_limit'] ?>" name="rl[<?= e_attr($b) ?>][limit]" value="<?= e_attr((string) $lim) ?>" <?= $canManage ? '' : 'disabled' ?>></td>
                    <td><label class="sr-only" for="rl-<?= e_attr($b) ?>-window">Window in seconds for <?= e($r['label']) ?></label>
                        <input id="rl-<?= e_attr($b) ?>-window" class="form-input w-28" type="number" inputmode="numeric" min="10" max="86400" name="rl[<?= e_attr($b) ?>][window]" value="<?= e_attr((string) $win) ?>" <?= $canManage ? '' : 'disabled' ?>></td>
                    <td class="text-xs text-slate-500"><?= (int) $r['default_limit'] ?> / <?= e($dur($r['default_window'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($canManage): ?>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="submit" class="btn btn-primary">Save limits</button>
            <button type="submit" form="reset-all" class="btn btn-ghost ml-auto text-red-600" data-confirm="Put every limit back to its default?">Reset everything to the defaults</button>
        </div>
        <p class="mt-2 text-xs text-slate-500">Saving asks you to confirm your password.</p>
    <?php endif ?>
</form>
<?php if ($canManage): ?><form id="reset-all" method="post" action="/admin/security/rate-limits/reset"><?= csrf_field() ?></form><?php endif ?>
<?php $this->stop(); ?>
