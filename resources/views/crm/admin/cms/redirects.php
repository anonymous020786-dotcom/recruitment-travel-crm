<?php
/**
 * @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $perPage @var string $q
 * @var array<string,mixed>|null $edit @var array<int,string> $codes @var bool $canPublish @var list<string>|null $importResult
 */
$this->layout('layouts.app', ['title' => 'Redirects — Pages', 'currentPath' => '/admin/cms']);
$this->start('content');

$f = static fn (string $k, mixed $d = ''): string => (string) old($k, $edit !== null ? ($k === 'from' ? $edit['from_path'] : ($k === 'to' ? ($edit['to_url'] ?? '') : ($k === 'code' ? $edit['status_code'] : ($edit[$k] ?? $d)))) : $d);
$err = static fn (string $k): string => ($e = error($k)) === null ? '' : '<p class="mt-1 text-xs text-red-600" role="alert">' . e($e) . '</p>';
?>
<?= component('page-header', ['title' => 'Website', 'subtitle' => 'Send visitors and search engines from old addresses to new ones, so moved pages keep their traffic.']) ?>
<?= $this->partial('crm.admin.cms._tabs', ['active' => 'redirects']) ?>

<?php if (!empty($importResult)): ?>
    <div class="mb-4"><?= component('alert', ['type' => 'warning', 'title' => 'Some lines were skipped', 'slot' => '<ul class="list-disc pl-5 text-xs">' . implode('', array_map(static fn (string $l): string => '<li>' . e($l) . '</li>', $importResult)) . '</ul>']) ?></div>
<?php endif ?>

<?php if ($canPublish): ?>
    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        <form method="post" action="/admin/cms/redirects<?= $edit !== null ? '/' . (int) $edit['id'] : '' ?>" class="card card-body space-y-3" data-once>
            <?= csrf_field() ?><?php if ($edit !== null): ?><input type="hidden" name="_method" value="PUT"><?php endif ?>
            <h2 class="text-sm font-semibold text-slate-900"><?= $edit !== null ? 'Edit redirect' : 'Add a redirect' ?></h2>
            <div><label class="form-label" for="rd-from">Old address</label><input id="rd-from" class="form-input font-mono" name="from" value="<?= e_attr($f('from')) ?>" placeholder="/old-page.html" required maxlength="300" spellcheck="false"><?= $err('from') ?></div>
            <div><label class="form-label" for="rd-code">Type</label>
                <select id="rd-code" class="form-select" name="code"><?php foreach ($codes as $c => $l): ?><option value="<?= (int) $c ?>" <?= (int) $f('code', 301) === $c ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><?= $err('code') ?></div>
            <div><label class="form-label" for="rd-to">New address <span class="font-normal text-slate-400">(empty for 410)</span></label><input id="rd-to" class="form-input font-mono" name="to" value="<?= e_attr($f('to')) ?>" placeholder="/new-page or https://…" maxlength="500" spellcheck="false"><?= $err('to') ?></div>
            <div><label class="form-label" for="rd-note">Note</label><input id="rd-note" class="form-input" name="note" value="<?= e_attr($f('note')) ?>" maxlength="200"><?= $err('note') ?></div>
            <div class="flex flex-wrap gap-4 text-sm text-slate-700">
                <label class="flex items-center gap-2"><input type="checkbox" name="keep_query" value="1" <?= $f('keep_query', '1') === '1' ? 'checked' : '' ?>> Keep ?query parameters</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" <?= $f('is_active', '1') === '1' ? 'checked' : '' ?>> Active</label>
            </div>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary"><?= $edit !== null ? 'Save' : 'Add redirect' ?></button><?php if ($edit !== null): ?><a class="btn btn-ghost" href="/admin/cms/redirects">Cancel</a><?php endif ?></div>
            <p class="text-xs text-slate-500">Chains are refused: point straight at the final address. When you move an address that other redirects already point to, they are re-pointed automatically.</p>
        </form>

        <form method="post" action="/admin/cms/redirects/import" class="card card-body space-y-3" data-once>
            <?= csrf_field() ?>
            <h2 class="text-sm font-semibold text-slate-900">Import many</h2>
            <label class="form-label" for="rd-import">One per line: <code>old-address, new-address, code</code> (code optional, default 301)</label>
            <textarea id="rd-import" class="form-input font-mono text-xs" name="import" rows="8" placeholder="/old-about.html, /about&#10;/services/visa.php, /visa-services, 301&#10;/old-offer, , 410"><?= e((string) old('import', '')) ?></textarea>
            <?= $err('import') ?>
            <div><button type="submit" class="btn btn-secondary">Import</button> <span class="text-xs text-slate-500">Up to 500 lines. Existing old addresses are updated.</span></div>
        </form>
    </div>
<?php endif ?>

<form method="get" action="/admin/cms/redirects" class="mb-4 flex max-w-lg gap-2" role="search">
    <label class="sr-only" for="rd-q">Search redirects</label>
    <input id="rd-q" class="form-input" type="search" name="q" value="<?= e_attr($q) ?>" placeholder="Search old or new address" maxlength="120">
    <button type="submit" class="btn btn-secondary">Search</button>
</form>

<?php if ($rows === []): ?>
    <div class="card card-body text-sm text-slate-600"><?= $q !== '' ? 'No redirect matches.' : 'No redirects yet.' ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Redirects">
            <thead><tr><th>Old address</th><th>Goes to</th><th>Type</th><th>Used</th><th>Status</th><?php if ($canPublish): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
                <tr>
                    <td class="font-mono text-xs"><?= e((string) $r['from_path']) ?><?= $r['note'] ? '<p class="font-sans text-slate-500">' . e((string) $r['note']) . '</p>' : '' ?></td>
                    <td class="max-w-xs truncate font-mono text-xs"><?= $r['to_url'] === null ? '<span class="text-slate-400">— gone —</span>' : e((string) $r['to_url']) ?></td>
                    <td class="text-xs"><?= (int) $r['status_code'] ?></td>
                    <td class="text-xs text-slate-600"><?= number_format((int) $r['hits']) ?><?= $r['last_hit_at'] ? '<br><span class="text-slate-400">last ' . e(substr((string) $r['last_hit_at'], 0, 10)) . '</span>' : '' ?></td>
                    <td><?= component('badge', ['label' => (int) $r['is_active'] === 1 ? 'Active' : 'Off', 'color' => (int) $r['is_active'] === 1 ? 'green' : 'slate', 'dot' => true]) ?></td>
                    <?php if ($canPublish): ?>
                        <td class="whitespace-nowrap text-right">
                            <a class="btn btn-ghost btn-sm" href="/admin/cms/redirects?edit=<?= $id ?>">Edit</a>
                            <form method="post" action="/admin/cms/redirects/<?= $id ?>/toggle" class="inline"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm"><?= (int) $r['is_active'] === 1 ? 'Switch off' : 'Switch on' ?></button></form>
                            <form method="post" action="/admin/cms/redirects/<?= $id ?>/delete" class="inline" data-confirm="Remove this redirect?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm text-red-600">Remove</button></form>
                        </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/admin/cms/redirects', 'query' => array_filter(['q' => $q])]) ?>
<?php endif ?>
<?php $this->stop(); ?>
