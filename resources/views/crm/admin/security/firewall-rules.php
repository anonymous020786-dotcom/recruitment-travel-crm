<?php
/**
 * @var list<array<string,mixed>> $rules @var array<string,mixed>|null $edit @var array<string,string> $parts @var array<string,string> $operators
 * @var array<string,string> $actions @var bool $canManage
 */
$this->layout('layouts.app', ['title' => 'Firewall rules — Security', 'currentPath' => '/admin/security']);
$this->start('content');
$f = static fn (string $k, mixed $d = ''): string => (string) old($k, $edit[$k] ?? $d);
$err = static fn (string $k): string => ($e = error($k)) === null ? '' : '<p class="mt-1 text-xs text-red-600" role="alert">' . e($e) . '</p>';
$tone = ['block' => 'red', 'log' => 'amber', 'allow' => 'green'];
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'Your own firewall rules. They run before the managed rule sets, lowest priority number first; an allow rule skips the rest.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'firewall']) ?>
<?= $this->partial('crm.admin.security._firewall-nav', ['active' => 'rules']) ?>

<?php if ($canManage): ?>
    <form method="post" action="/admin/security/firewall/rules<?= $edit !== null ? '/' . (int) $edit['id'] : '' ?>" class="card card-body mb-6 max-w-4xl space-y-3" data-once>
        <?= csrf_field() ?><?php if ($edit !== null): ?><input type="hidden" name="_method" value="PUT"><?php endif ?>
        <h2 class="text-sm font-semibold text-slate-900"><?= $edit !== null ? 'Edit rule' : 'Add a rule' ?></h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="form-label" for="r-name">Name</label><input id="r-name" class="form-input" name="name" value="<?= e_attr($f('name')) ?>" maxlength="80" required placeholder="e.g. Block the old admin path"><?= $err('name') ?></div>
            <div><label class="form-label" for="r-action">Then</label><select id="r-action" class="form-select" name="action"><?php foreach ($actions as $k => $l): ?><option value="<?= $k ?>" <?= $f('action', 'block') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><?= $err('action') ?></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-[1fr_1fr_2fr]">
            <div><label class="form-label" for="r-part">If the</label><select id="r-part" class="form-select" name="part"><?php foreach ($parts as $k => $l): ?><option value="<?= $k ?>" <?= $f('part', 'path') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><?= $err('part') ?></div>
            <div><label class="form-label" for="r-op">…</label><select id="r-op" class="form-select" name="operator"><?php foreach ($operators as $k => $l): ?><option value="<?= $k ?>" <?= $f('operator', 'contains') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><?= $err('operator') ?></div>
            <div><label class="form-label" for="r-value">Value</label><input id="r-value" class="form-input font-mono" name="value" value="<?= e_attr($f('value')) ?>" maxlength="500" required spellcheck="false"><?= $err('value') ?></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-4">
            <div><label class="form-label" for="r-h">Header name <span class="font-normal text-slate-400">(for "a header")</span></label><input id="r-h" class="form-input font-mono" name="header_name" value="<?= e_attr($f('header_name')) ?>" maxlength="60"><?= $err('header_name') ?></div>
            <div><label class="form-label" for="r-p">Priority</label><input id="r-p" class="form-input" type="number" min="0" max="9999" name="priority" value="<?= e_attr($f('priority', '100')) ?>"><?= $err('priority') ?></div>
            <div><label class="form-label" for="r-x">Expires after (hours)</label><input id="r-x" class="form-input" type="number" min="1" max="8760" name="expires_in_hours" value="<?= e_attr((string) old('expires_in_hours', '')) ?>" placeholder="never"><?= $err('expires_in_hours') ?></div>
            <div class="flex flex-col justify-end gap-1 text-sm text-slate-700">
                <label class="flex items-center gap-2"><input type="checkbox" name="negate" value="1" <?= $f('negate') === '1' ? 'checked' : '' ?>> Invert (does NOT match)</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" <?= $f('is_active', '1') === '1' ? 'checked' : '' ?>> Active</label>
            </div>
        </div>
        <div><label class="form-label" for="r-note">Note</label><input id="r-note" class="form-input" name="note" value="<?= e_attr($f('note')) ?>" maxlength="200"><?= $err('note') ?></div>
        <p class="text-xs text-slate-500">Comparisons ignore upper/lower case. "Is one of" takes a comma-separated list; "is in IP range" takes addresses or ranges like 203.0.113.0/24. Try a rule on the Test page before relying on it.</p>
        <div class="flex gap-2"><button type="submit" class="btn btn-primary"><?= $edit !== null ? 'Save rule' : 'Add rule' ?></button><?php if ($edit !== null): ?><a class="btn btn-ghost" href="/admin/security/firewall/rules">Cancel</a><?php endif ?></div>
    </form>
<?php endif ?>

<?php if ($rules === []): ?>
    <div class="card card-body text-sm text-slate-600">No custom rules. The managed rule sets are protecting the site on their own.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Custom firewall rules">
            <thead><tr><th>#</th><th>Rule</th><th>Condition</th><th>Action</th><th>Hits</th><th>Status</th><?php if ($canManage): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
            <tbody>
            <?php foreach ($rules as $r): $id = (int) $r['id']; $expired = (int) $r['expired'] === 1; ?>
                <tr<?= $expired || (int) $r['is_active'] === 0 ? ' class="opacity-60"' : '' ?>>
                    <td class="text-xs text-slate-500"><?= (int) $r['priority'] ?></td>
                    <td><span class="font-medium text-slate-900"><?= e((string) $r['name']) ?></span><?= $r['note'] ? '<p class="text-xs text-slate-500">' . e((string) $r['note']) . '</p>' : '' ?></td>
                    <td class="text-xs"><?= e($parts[$r['part']] ?? (string) $r['part']) ?><?= $r['header_name'] ? ' <span class="font-mono">' . e((string) $r['header_name']) . '</span>' : '' ?> <?= (int) $r['negate'] === 1 ? '<strong>not</strong> ' : '' ?><?= e($operators[$r['operator']] ?? (string) $r['operator']) ?> <span class="font-mono"><?= e(mb_substr((string) $r['value'], 0, 80)) ?></span></td>
                    <td><?= component('badge', ['label' => $actions[$r['action']] ?? (string) $r['action'], 'color' => $tone[$r['action']] ?? 'slate']) ?></td>
                    <td class="text-xs text-slate-600"><?= number_format((int) $r['hits']) ?><?= $r['last_hit_at'] ? '<br><span class="text-slate-400">' . e(substr((string) $r['last_hit_at'], 0, 16)) . '</span>' : '' ?></td>
                    <td class="text-xs"><?= $expired ? 'expired' : ((int) $r['is_active'] === 1 ? 'active' : 'off') ?><?= $r['expires_at'] && !$expired ? '<br><span class="text-slate-400">until ' . e(substr((string) $r['expires_at'], 0, 16)) . '</span>' : '' ?></td>
                    <?php if ($canManage): ?>
                        <td class="whitespace-nowrap text-right">
                            <a class="btn btn-ghost btn-sm" href="/admin/security/firewall/rules?edit=<?= $id ?>">Edit</a>
                            <form method="post" action="/admin/security/firewall/rules/<?= $id ?>/toggle" class="inline"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm"><?= (int) $r['is_active'] === 1 ? 'Switch off' : 'Switch on' ?></button></form>
                            <form method="post" action="/admin/security/firewall/rules/<?= $id ?>/delete" class="inline" data-confirm="Remove this rule?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm text-red-600">Remove</button></form>
                        </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
