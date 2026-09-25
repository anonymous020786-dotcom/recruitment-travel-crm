<?php
/**
 * @var string $active @var string $chosen @var string $delivery @var array<string,array{count:int,bytes:int}> $byDisk
 * @var array<string,array{label:string,state:string,label2?:string}> $providers @var array{pending:int,bytes:int} $pending
 * @var list<array{key:string,label:string,storage:float,egress:float,requests:float,total:float,saving:float,note:string}> $estimate
 * @var array{as_of:string,currency:string,providers:array<string,array<string,mixed>>} $pricing
 * @var array{read_percent:float,new_files:int,cold_percent:float,total_gb:float} $inputs @var bool $canManage
 */
$this->layout('layouts.app', ['title' => 'Storage', 'currentPath' => '/admin/storage']);
$this->start('content');

$names = ['private' => 'This server', 's3' => 'Amazon S3', 'r2' => 'Cloudflare R2'];
$size = static fn (int $b): string => $b >= 1_073_741_824 ? number_format($b / 1_073_741_824, 2) . ' GB' : ($b >= 1_048_576 ? number_format($b / 1_048_576, 1) . ' MB' : number_format($b / 1024, 0) . ' KB');
$usd = static fn (float $v): string => '$' . number_format($v, 2);
$post = static fn (string $action, string $label, string $cls = 'btn-secondary', string $extra = '', string $confirm = ''): string
    => '<form method="post" action="' . $action . '" class="inline">' . csrf_field() . $extra
        . '<button type="submit" class="btn ' . $cls . ' btn-sm"' . ($confirm !== '' ? ' data-confirm="' . e_attr($confirm) . '"' : '') . '>' . e($label) . '</button></form>';
$tone = ['configured' => 'green', 'incomplete' => 'amber', 'off' => 'slate', 'empty' => 'slate'];
?>
<?= component('page-header', [
    'title' => 'Storage',
    'subtitle' => 'Where documents are kept, what it costs, and how to spend less.',
    'actions' => can('integrations.manage') ? '<a class="btn btn-secondary btn-sm" href="/admin/integrations/storage">Storage settings</a>' : '',
]) ?>

<section class="mb-6 grid gap-4 lg:grid-cols-2" aria-label="Where documents live">
    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">New uploads go to</h2>
        <p class="mb-3"><?= component('badge', ['label' => $names[$active] ?? $active, 'color' => $active === 'private' ? 'slate' : 'green', 'dot' => true]) ?>
            <span class="ml-2 text-xs text-slate-500">Downloads are delivered <?= $delivery === 'redirect' && $active !== 'private' ? 'by a short-lived signed link from the bucket' : 'through this server' ?>.</span></p>
        <?php if ($chosen !== $active): ?>
            <p class="mb-3 text-sm text-amber-700">You chose <?= e($names[$chosen] ?? $chosen) ?> but it is not fully configured, so uploads stay on the server. Add its keys under Integrations.</p>
        <?php endif ?>
        <table class="data" aria-label="Documents by location">
            <thead><tr><th>Location</th><th>Documents</th><th>Size</th></tr></thead>
            <tbody>
            <?php foreach ($names as $disk => $label): $u = $byDisk[$disk] ?? ['count' => 0, 'bytes' => 0]; ?>
                <tr><td><?= e($label) ?></td><td><?= number_format($u['count']) ?></td><td><?= e($size($u['bytes'])) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">Providers</h2>
        <ul class="space-y-2">
            <?php foreach ($providers as $key => $p): ?>
                <li class="flex flex-wrap items-center justify-between gap-2">
                    <span><a class="font-medium text-slate-900" href="/admin/integrations/<?= e_attr($key) ?>"><?= e($p['label']) ?></a>
                        <?= component('badge', ['label' => $p['state'] === 'configured' ? 'Configured' : ($p['state'] === 'incomplete' ? 'Incomplete' : ($p['state'] === 'off' ? 'Off' : 'Not set up')), 'color' => $tone[$p['state']] ?? 'slate']) ?></span>
                    <?php if ($canManage && $p['state'] === 'configured'): ?><?= $post('/admin/storage/test', 'Test connection', 'btn-secondary', '<input type="hidden" name="provider" value="' . e_attr($key) . '">') ?><?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
        <?php if ($canManage && $active !== 'private'): ?>
            <hr class="my-3 border-slate-200">
            <p class="text-sm text-slate-700"><strong><?= number_format($pending['pending']) ?></strong> document<?= $pending['pending'] === 1 ? '' : 's' ?> (<?= e($size($pending['bytes'])) ?>) still on this server.</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <?php if ($pending['pending'] > 0): ?><?= $post('/admin/storage/migrate', 'Move the next 25 to ' . ($names[$active] ?? $active), 'btn-primary', '', 'Copy the next 25 documents to the bucket, verify each one, then remove the server copy?') ?><?php endif ?>
                <?= $post('/admin/storage/lifecycle', 'Apply cost-saving rules', 'btn-secondary', '', 'Set the bucket lifecycle rules (cheaper storage for old documents, backup expiry, cleanup of unfinished uploads)? This replaces any lifecycle rules already on the bucket.') ?>
            </div>
        <?php endif ?>
    </div>
</section>

<section class="card card-body" aria-labelledby="cost-h">
    <h2 id="cost-h" class="text-sm font-semibold text-slate-900">Monthly cost estimate</h2>
    <p class="mt-1 text-xs text-slate-500">
        Based on <?= e((string) $inputs['total_gb']) ?> GB stored, <?= e((string) $inputs['cold_percent']) ?>% of it older than the tier-down age, and the traffic below. List prices in <?= e($pricing['currency']) ?> as of <?= e($pricing['as_of']) ?> —
        an estimate for comparing options, not a quote (<?php foreach ($pricing['providers'] as $p): ?><a class="text-brand-600" href="<?= e_attr((string) $p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $p['label']) ?><span class="sr-only"> pricing (opens in a new tab)</span></a> · <?php endforeach ?>check them before deciding). Keeping files on this server costs nothing extra but is limited by your hosting plan's disk.
    </p>

    <form method="get" action="/admin/storage" class="my-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="form-label" for="read_percent">Share of stored data downloaded each month (%)</label>
            <input class="form-input" type="number" id="read_percent" name="read_percent" min="0" max="100" step="0.1" value="<?= e_attr((string) $inputs['read_percent']) ?>">
        </div>
        <div>
            <label class="form-label" for="new_files">New files per month</label>
            <input class="form-input" type="number" id="new_files" name="new_files" min="0" max="1000000" value="<?= (int) $inputs['new_files'] ?>">
        </div>
        <button type="submit" class="btn btn-secondary">Recalculate</button>
    </form>

    <div class="table-wrap">
        <table class="data" aria-label="Cost comparison">
            <thead><tr><th>Option</th><th class="text-right">Storage</th><th class="text-right">Downloads</th><th class="text-right">Requests</th><th class="text-right">Total / month</th><th class="text-right">vs S3 Standard</th></tr></thead>
            <tbody>
            <?php foreach ($estimate as $i => $r): ?>
                <tr>
                    <td><span class="font-medium text-slate-900"><?= e($r['label']) ?></span><p class="text-xs text-slate-500"><?= e($r['note']) ?></p></td>
                    <td class="text-right"><?= e($usd($r['storage'])) ?></td>
                    <td class="text-right"><?= e($usd($r['egress'])) ?></td>
                    <td class="text-right"><?= e($usd($r['requests'])) ?></td>
                    <td class="text-right font-semibold"><?= e($usd($r['total'])) ?></td>
                    <td class="text-right <?= $r['saving'] > 0 ? 'text-green-700' : 'text-slate-500' ?>"><?= $i === 0 ? '—' : ($r['saving'] > 0 ? 'saves ' . e($usd($r['saving'])) : 'same') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
<?php $this->stop(); ?>
