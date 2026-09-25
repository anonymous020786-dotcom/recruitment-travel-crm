<?php
/** @var list<array<string,mixed>> $rules @var bool $canManage @var string $yourIp @var int $max */
$this->layout('layouts.app', ['title' => 'IP rules — Security', 'currentPath' => '/admin/security']);
$this->start('content');
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'Block or allow single addresses and ranges. An allow rule always wins over a block.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'ip-rules']) ?>

<?php if ($canManage): ?>
    <form method="post" action="/admin/security/ip-rules" class="card card-body mb-6 max-w-3xl" data-once>
        <?= csrf_field() ?>
        <h2 class="mb-3 text-sm font-semibold text-slate-900">Add a rule</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="form-label" for="ip-effect">Action</label>
                <select id="ip-effect" class="form-select" name="effect">
                    <option value="block" <?= old('effect', 'block') === 'block' ? 'selected' : '' ?>>Block</option>
                    <option value="allow" <?= old('effect') === 'allow' ? 'selected' : '' ?>>Always allow</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="ip-cidr">Address or range</label>
                <input id="ip-cidr" class="form-input font-mono" name="cidr" value="<?= e_attr((string) old('cidr', '')) ?>" placeholder="203.0.113.7 or 203.0.113.0/24" maxlength="50" required spellcheck="false" autocomplete="off">
                <?php if (($e = error('cidr')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
                <p class="mt-1 text-xs text-slate-500">You are connecting from <span class="font-mono"><?= e($yourIp) ?></span>.</p>
            </div>
            <div>
                <label class="form-label" for="ip-minutes">Lasts (minutes, empty = until removed)</label>
                <input id="ip-minutes" class="form-input" type="number" inputmode="numeric" min="1" max="525600" name="minutes" value="<?= e_attr((string) old('minutes', '')) ?>">
                <?php if (($e = error('minutes')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
            </div>
            <div>
                <label class="form-label" for="ip-note">Note</label>
                <input id="ip-note" class="form-input" name="note" value="<?= e_attr((string) old('note', '')) ?>" maxlength="200" placeholder="Why?">
                <?php if (($e = error('note')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?>
            </div>
        </div>
        <div class="mt-4"><button type="submit" class="btn btn-primary">Add rule</button> <span class="text-xs text-slate-500">Asks you to confirm your password. Up to <?= (int) $max ?> rules.</span></div>
    </form>
<?php endif ?>

<?php if ($rules === []): ?>
    <div class="card card-body max-w-3xl text-sm text-slate-600">No rules yet — nobody is blocked. If you ever lock yourself out, run <code>php scripts/security-unblock.php</code> on the server.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="IP rules">
            <thead><tr><th>Action</th><th>Address / range</th><th>Note</th><th>Expires</th><th>Last turned away</th><th>Added</th><?php if ($canManage): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
            <tbody>
            <?php foreach ($rules as $r): ?>
                <tr>
                    <td><?= component('badge', ['label' => $r['effect'] === 'allow' ? 'Allow' : 'Block', 'color' => $r['effect'] === 'allow' ? 'green' : 'red', 'dot' => true]) ?><?= $r['source'] === 'auto' ? ' <span class="text-xs text-slate-500">auto</span>' : '' ?></td>
                    <td class="font-mono text-xs"><?= e((string) $r['cidr']) ?></td>
                    <td class="text-sm text-slate-600"><?= e((string) ($r['note'] ?? '')) ?></td>
                    <td class="text-xs <?= (int) $r['active'] === 1 ? 'text-slate-600' : 'text-slate-400' ?>"><?= $r['expires_at'] === null ? 'never' : e(substr((string) $r['expires_at'], 0, 16)) . ((int) $r['active'] === 1 ? '' : ' (expired)') ?></td>
                    <td class="text-xs text-slate-500"><?= $r['last_blocked_at'] === null ? '—' : e(substr((string) $r['last_blocked_at'], 0, 16)) ?></td>
                    <td class="text-xs text-slate-500"><?= e(substr((string) $r['created_at'], 0, 10)) ?><?= $r['created_by_name'] ? ' · ' . e((string) $r['created_by_name']) : '' ?></td>
                    <?php if ($canManage): ?>
                        <td class="text-right"><form method="post" action="/admin/security/ip-rules/<?= (int) $r['id'] ?>/remove" data-confirm="Remove this rule?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm text-red-600">Remove<span class="sr-only"> <?= e((string) $r['cidr']) ?></span></button></form></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
