<?php
/**
 * @var array{block:int,log:int,ban:int,addresses:int} $counts @var list<array<string,mixed>> $topRules @var list<array<string,mixed>> $topIps
 * @var list<array{hour:string,n:int}> $hourly @var list<array<string,mixed>> $events @var int $total @var int $page
 * @var array{action:string,rule:string,ip:string} $filters @var array<string,mixed> $settings @var bool $canManage @var string $yourIp
 */
$this->layout('layouts.app', ['title' => 'Firewall — Security', 'currentPath' => '/admin/security']);
$this->start('content');
$ipText = static fn (mixed $b): string => is_string($b) && $b !== '' && ($t = @inet_ntop($b)) !== false ? (str_starts_with($t, '::ffff:') ? substr($t, 7) : $t) : '—';
$peak = max(1, max(array_column($hourly, 'n')));
// fixed classes (the CSP forbids inline style attributes): 0–10 → a tenth of the chart height each
$heights = ['h-[3%]', 'h-[10%]', 'h-[20%]', 'h-[30%]', 'h-[40%]', 'h-[50%]', 'h-[60%]', 'h-[70%]', 'h-[80%]', 'h-[90%]', 'h-full'];
$tone = ['block' => 'red', 'log' => 'amber', 'ban' => 'red'];
$blockForm = static fn (string $ip): string => '<form method="post" action="/admin/security/ip-rules" class="inline" data-confirm="Block ' . e_attr($ip) . ' for 24 hours?">' . csrf_field()
    . '<input type="hidden" name="effect" value="block"><input type="hidden" name="cidr" value="' . e_attr($ip) . '"><input type="hidden" name="minutes" value="1440"><input type="hidden" name="note" value="Blocked from the firewall log">'
    . '<button type="submit" class="btn btn-ghost btn-sm text-red-600">Block 24 h</button></form>';
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'The application firewall inspects every request before the site answers it.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'firewall']) ?>
<?= $this->partial('crm.admin.security._firewall-nav', ['active' => 'overview']) ?>

<?php if ($settings['enabled'] === '0'): ?>
    <div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => 'The firewall is switched off. Nothing is being inspected.']) ?></div>
<?php elseif ($settings['monitor'] === '1'): ?>
    <div class="mb-4"><?= component('alert', ['type' => 'warning', 'message' => 'Monitor mode: requests that would be blocked are only logged. Switch it off once you are happy with what you see.']) ?></div>
<?php endif ?>
<?php if ($settings['lockdown'] === '1'): ?><div class="mb-4"><?= component('alert', ['type' => 'info', 'message' => 'Lockdown is on: only allow-listed addresses reach sign-in and the staff area.']) ?></div><?php endif ?>

<section class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Last 24 hours">
    <?= component('stat', ['label' => 'Blocked, 24 h', 'value' => $counts['block'], 'hint' => 'requests turned away']) ?>
    <?= component('stat', ['label' => 'Logged only, 24 h', 'value' => $counts['log'], 'hint' => 'suspicious but allowed']) ?>
    <?= component('stat', ['label' => 'Addresses banned, 24 h', 'value' => $counts['ban'], 'hint' => 'automatic bans', 'href' => '/admin/security/ip-rules']) ?>
    <?= component('stat', ['label' => 'Different addresses', 'value' => $counts['addresses'], 'hint' => 'seen in the log']) ?>
</section>

<section class="card card-body mb-6" aria-labelledby="fw-hours">
    <h2 id="fw-hours" class="mb-3 text-sm font-semibold text-slate-900">Events per hour (UTC)</h2>
    <div class="flex h-24 items-end gap-1" role="img" aria-label="Events per hour over the last 24 hours, peak <?= (int) $peak ?>">
        <?php foreach ($hourly as $h): ?>
            <span class="flex-1 rounded-t <?= $h['n'] > 0 ? 'bg-brand-500' : 'bg-slate-200' ?> <?= $heights[$h['n'] > 0 ? max(1, (int) round($h['n'] / $peak * 10)) : 0] ?>" title="<?= e_attr(substr($h['hour'], 11) . ': ' . $h['n']) ?>"></span>
        <?php endforeach ?>
    </div>
    <div class="mt-1 flex justify-between text-xs text-slate-500"><span><?= e(substr($hourly[0]['hour'], 11)) ?></span><span>now</span></div>
</section>

<section class="mb-6 grid gap-4 lg:grid-cols-2" aria-label="Top sources">
    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">Busiest rules, 24 h</h2>
        <?php if ($topRules === []): ?><p class="text-sm text-slate-500">Quiet — nothing matched.</p><?php else: ?>
            <ul class="space-y-1 text-sm"><?php foreach ($topRules as $r): ?><li class="flex justify-between gap-2"><a href="/admin/security/firewall?rule=<?= e_attr((string) $r['rule_key']) ?>"><?= e((string) $r['rule_label']) ?></a><span class="text-slate-500"><?= number_format((int) $r['n']) ?></span></li><?php endforeach ?></ul>
        <?php endif ?>
    </div>
    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">Busiest addresses, 24 h</h2>
        <?php if ($topIps === []): ?><p class="text-sm text-slate-500">Nothing yet.</p><?php else: ?>
            <ul class="space-y-1 text-sm"><?php foreach ($topIps as $r): $ip = $ipText($r['ip_address']); ?>
                <li class="flex flex-wrap items-center justify-between gap-2"><a class="font-mono text-xs" href="/admin/security/firewall?ip=<?= e_attr($ip) ?>"><?= e($ip) ?></a><span class="text-slate-500"><?= number_format((int) $r['n']) ?><?= $canManage && $ip !== $yourIp && $ip !== '—' ? ' ' . $blockForm($ip) : '' ?></span></li>
            <?php endforeach ?></ul>
        <?php endif ?>
    </div>
</section>

<section aria-labelledby="fw-log">
    <h2 id="fw-log" class="mb-2 text-sm font-semibold text-slate-900">Event log <span class="font-normal text-slate-500">(kept 30 days)</span></h2>
    <form method="get" action="/admin/security/firewall" class="mb-3 flex flex-wrap items-end gap-2">
        <div><label class="form-label" for="fw-a">Action</label><select id="fw-a" class="form-select" name="action"><option value="">Any</option><?php foreach (['block' => 'Blocked', 'log' => 'Logged', 'ban' => 'Banned'] as $k => $l): ?><option value="<?= $k ?>" <?= $filters['action'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="fw-ip">Address</label><input id="fw-ip" class="form-input font-mono" name="ip" value="<?= e_attr($filters['ip']) ?>" maxlength="45"></div>
        <?php if ($filters['rule'] !== ''): ?><input type="hidden" name="rule" value="<?= e_attr($filters['rule']) ?>"><?php endif ?>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if (array_filter($filters) !== []): ?><a class="btn btn-ghost" href="/admin/security/firewall">Clear</a><?php endif ?>
    </form>
    <?php if ($events === []): ?>
        <div class="card card-body text-sm text-slate-600">No events.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data" aria-label="Firewall events">
                <thead><tr><th>When (UTC)</th><th>Action</th><th>Rule</th><th>Request</th><th>What matched</th><th>Address</th></tr></thead>
                <tbody>
                <?php foreach ($events as $ev): $ip = $ipText($ev['ip_address']); ?>
                    <tr>
                        <td class="whitespace-nowrap text-xs text-slate-500"><?= e(substr((string) $ev['created_at'], 0, 19)) ?><?= $ev['request_id'] ? '<br><span class="font-mono">' . e((string) $ev['request_id']) . '</span>' : '' ?></td>
                        <td><?= component('badge', ['label' => ucfirst((string) $ev['action']), 'color' => $tone[$ev['action']] ?? 'slate', 'dot' => true]) ?></td>
                        <td class="text-sm"><?= e((string) $ev['rule_label']) ?></td>
                        <td class="max-w-xs truncate font-mono text-xs" title="<?= e_attr((string) $ev['path']) ?>"><?= e((string) $ev['method']) ?> <?= e((string) $ev['path']) ?></td>
                        <td class="max-w-xs truncate font-mono text-xs text-red-800" title="<?= e_attr((string) ($ev['sample'] ?? '')) ?>"><?= $ev['part'] ? e((string) $ev['part']) . ': ' : '' ?><?= e((string) ($ev['sample'] ?? '')) ?></td>
                        <td class="whitespace-nowrap font-mono text-xs"><?= e($ip) ?><?= $ev['country'] ? ' · ' . e((string) $ev['country']) : '' ?><?= $canManage && $ip !== $yourIp && $ip !== '—' ? '<br>' . $blockForm($ip) : '' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?= component('pagination', ['page' => $page, 'perPage' => 50, 'total' => $total, 'baseUrl' => '/admin/security/firewall', 'query' => array_filter($filters)]) ?>
    <?php endif ?>
</section>
<?php $this->stop(); ?>
