<?php
/**
 * @var array<string,mixed> $settings @var array<string,array<string,mixed>> $sets @var list<array{code:string,name:string}> $countries
 * @var bool $canManage @var string $yourIp @var string|null $yourCountry
 */
$this->layout('layouts.app', ['title' => 'Firewall settings — Security', 'currentPath' => '/admin/security']);
$this->start('content');
$dis = $canManage ? '' : ' disabled';
$err = static fn (string $k): string => ($e = error($k)) === null ? '' : '<p class="mt-1 text-xs text-red-600" role="alert">' . e($e) . '</p>';
$chosen = array_filter(explode(',', (string) $settings['geo_countries']));
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'Choose how strict the firewall is, who may reach the staff area, and from which countries.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'firewall']) ?>
<?= $this->partial('crm.admin.security._firewall-nav', ['active' => 'settings']) ?>

<form method="post" action="/admin/security/firewall/settings" class="card card-body mb-6 max-w-4xl space-y-5" data-once>
    <?= csrf_field() ?><input type="hidden" name="_method" value="PUT">
    <h2 class="text-sm font-semibold text-slate-900">Protection</h2>
    <div class="flex flex-wrap gap-6 text-sm text-slate-700">
        <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] !== '0' ? 'checked' : '' ?><?= $dis ?>> Firewall switched on</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="monitor" value="1" <?= $settings['monitor'] === '1' ? 'checked' : '' ?><?= $dis ?>> Monitor mode (log, never block)</label>
    </div>
    <?= $err('modes') ?>
    <div class="table-wrap">
        <table class="data" aria-label="Managed rule sets">
            <thead><tr><th>Rule set</th><th>Mode</th></tr></thead>
            <tbody>
            <?php foreach ($sets as $key => $s): ?>
                <tr>
                    <td><span class="font-medium text-slate-900"><?= e((string) $s['label']) ?></span><p class="text-xs text-slate-500"><?= e((string) $s['help']) ?></p></td>
                    <td><label class="sr-only" for="mode-<?= e_attr($key) ?>">Mode for <?= e((string) $s['label']) ?></label>
                        <select id="mode-<?= e_attr($key) ?>" class="form-select" name="modes[<?= e_attr($key) ?>]"<?= $dis ?>>
                            <?php foreach (['block' => 'Block', 'log' => 'Log only', 'off' => 'Off'] as $m => $l): ?><option value="<?= $m ?>" <?= $settings['modes'][$key] === $m ? 'selected' : '' ?>><?= $l ?><?= $m === $s['default'] ? ' (default)' : '' ?></option><?php endforeach ?>
                        </select></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <h2 class="text-sm font-semibold text-slate-900">Automatic bans</h2>
    <div class="grid gap-3 sm:grid-cols-3">
        <div><label class="form-label" for="fw-et">Ban after this many blocked requests (0 = off)</label><input id="fw-et" class="form-input" type="number" min="0" max="1000" name="escalate_threshold" value="<?= e_attr((string) old('escalate_threshold', $settings['escalate_threshold'])) ?>"<?= $dis ?>><?= $err('escalate_threshold') ?></div>
        <div><label class="form-label" for="fw-em">…within, and ban for (minutes)</label><input id="fw-em" class="form-input" type="number" min="1" max="10080" name="escalate_minutes" value="<?= e_attr((string) old('escalate_minutes', $settings['escalate_minutes'])) ?>"<?= $dis ?>><?= $err('escalate_minutes') ?></div>
        <div><label class="form-label" for="fw-pb">Ban probers at once for (minutes, 0 = off)</label><input id="fw-pb" class="form-input" type="number" min="0" max="10080" name="probe_ban" value="<?= e_attr((string) old('probe_ban', $settings['probe_ban'])) ?>"<?= $dis ?>><?= $err('probe_ban') ?></div>
    </div>
    <p class="text-xs text-slate-500">Bans appear under IP rules as automatic, temporary blocks. Addresses on an allow rule are never banned or firewalled.</p>
    <?php if ($canManage): ?><div><button type="submit" class="btn btn-primary">Save protection settings</button> <span class="text-xs text-slate-500">Asks you to confirm your password.</span></div><?php endif ?>
</form>

<form method="post" action="/admin/security/firewall/geo" class="card card-body mb-6 max-w-4xl space-y-4" data-once>
    <?= csrf_field() ?>
    <h2 class="text-sm font-semibold text-slate-900">Countries</h2>
    <p class="text-xs text-slate-500">Works only behind Cloudflare (or another proxy that sends the <code>CF-IPCountry</code> header) listed in <code>TRUSTED_PROXIES</code>. <?= $yourCountry !== null ? 'You are connecting from ' . e($yourCountry) . '.' : 'No country is known for your connection right now, so country rules will not match anything yet.' ?></p>
    <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="form-label" for="fw-gs">Apply to</label>
            <select id="fw-gs" class="form-select" name="geo_scope"<?= $dis ?>><?php foreach (['off' => 'Nothing (off)', 'staff' => 'Sign-in and staff area only', 'site' => 'The whole site'] as $k => $l): ?><option value="<?= $k ?>" <?= $settings['geo_scope'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select><?= $err('geo_scope') ?></div>
        <div><label class="form-label" for="fw-gm">Rule</label>
            <select id="fw-gm" class="form-select" name="geo_mode"<?= $dis ?>><option value="block" <?= $settings['geo_mode'] === 'block' ? 'selected' : '' ?>>Block the chosen countries</option><option value="allow" <?= $settings['geo_mode'] === 'allow' ? 'selected' : '' ?>>Allow only the chosen countries</option></select><?= $err('geo_mode') ?></div>
    </div>
    <div><label class="form-label" for="fw-gc">Countries <span class="font-normal text-slate-400">(hold Ctrl / Cmd to pick several)</span></label>
        <select id="fw-gc" class="form-select h-48" name="countries[]" multiple<?= $dis ?>>
            <?php foreach ($countries as $c): ?><option value="<?= e_attr($c['code']) ?>" <?= in_array($c['code'], $chosen, true) ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['code']) ?>)</option><?php endforeach ?>
        </select><?= $err('geo_countries') ?></div>
    <?php if ($canManage): ?><div><button type="submit" class="btn btn-primary">Save country rules</button></div><?php endif ?>
</form>

<section class="card card-body max-w-4xl" aria-labelledby="fw-ld">
    <h2 id="fw-ld" class="text-sm font-semibold text-slate-900">Lockdown <?= component('badge', ['label' => $settings['lockdown'] === '1' ? 'On' : 'Off', 'color' => $settings['lockdown'] === '1' ? 'red' : 'slate', 'dot' => true]) ?></h2>
    <p class="mt-1 text-sm text-slate-600">During an attack, let only addresses on an IP <strong>allow</strong> rule reach sign-in and the staff area. The public website stays open. Your address is <span class="font-mono"><?= e($yourIp) ?></span>.</p>
    <?= $err('lockdown') ?>
    <?php if ($canManage): ?>
        <form method="post" action="/admin/security/firewall/lockdown" class="mt-3" data-confirm="<?= $settings['lockdown'] === '1' ? 'Switch lockdown off?' : 'Switch lockdown on? Only allow-listed addresses will reach sign-in.' ?>"><?= csrf_field() ?>
            <input type="hidden" name="on" value="<?= $settings['lockdown'] === '1' ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $settings['lockdown'] === '1' ? 'btn-secondary' : 'btn-danger' ?>"><?= $settings['lockdown'] === '1' ? 'Switch lockdown off' : 'Switch lockdown on' ?></button>
        </form>
        <p class="mt-2 text-xs text-slate-500">Locked out? On the server run <code>php scripts/security-unblock.php</code>.</p>
    <?php endif ?>
</section>
<?php $this->stop(); ?>
