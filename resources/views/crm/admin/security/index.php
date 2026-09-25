<?php
/**
 * @var array{sessions:int,people:int} $sessionCounts @var array{failed:int,succeeded:int,addresses:int} $attempts
 * @var list<array<string,mixed>> $top @var list<array<string,mixed>> $recent @var int $locked @var int $ipRuleCount
 * @var array<string,int> $gaps @var array{roles:list<string>,grace:int,source:string} $twoFactor @var int $customLimits
 * @var array{threshold:int,minutes:int} $autoBlock @var bool $canManage @var string $yourIp
 */
$this->layout('layouts.app', ['title' => 'Security', 'currentPath' => '/admin/security']);
$this->start('content');

$ipText = static fn (mixed $b): string => is_string($b) && $b !== '' && ($t = @inet_ntop($b)) !== false ? (str_starts_with($t, '::ffff:') ? substr($t, 7) : $t) : '—';
$gapTotal = array_sum($gaps);
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'Who is signed in, who is failing to, and the limits and rules that protect the system.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'overview']) ?>

<section class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="At a glance">
    <?= component('stat', ['label' => 'Signed in now', 'value' => $sessionCounts['people'], 'hint' => number_format($sessionCounts['sessions']) . ' session(s)', 'href' => '/admin/security/sessions']) ?>
    <?= component('stat', ['label' => 'Failed sign-ins, 24 h', 'value' => $attempts['failed'], 'hint' => $attempts['addresses'] . ' address(es) · ' . $attempts['succeeded'] . ' succeeded']) ?>
    <?= component('stat', ['label' => 'Locked accounts', 'value' => $locked, 'hint' => 'unlock from Admin → Users', 'href' => '/admin/users']) ?>
    <?= component('stat', ['label' => 'IP rules', 'value' => $ipRuleCount, 'hint' => $autoBlock['threshold'] > 0 ? 'auto-block after ' . $autoBlock['threshold'] . ' failures' : 'auto-block is off', 'href' => '/admin/security/ip-rules']) ?>
</section>

<section class="mb-6 grid gap-4 lg:grid-cols-2" aria-label="Policy">
    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">Two-factor authentication</h2>
        <?php if ($twoFactor['roles'] === []): ?>
            <p class="text-sm text-slate-600">Not required for any role. People can still turn it on from their account page.</p>
        <?php else: ?>
            <p class="text-sm text-slate-600">Required for <?= e(implode(', ', $twoFactor['roles'])) ?> (<?= (int) $twoFactor['grace'] ?> sign-in(s) to set it up).</p>
            <?php if ($gapTotal > 0): ?><p class="mt-1 text-sm text-amber-700"><?= (int) $gapTotal ?> active person(s) in those roles have not set it up yet.</p><?php endif ?>
        <?php endif ?>
        <p class="mt-3"><a class="btn btn-secondary btn-sm" href="/admin/security/policy">Change</a></p>
    </div>
    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">Rate limits</h2>
        <p class="text-sm text-slate-600"><?= $customLimits > 0 ? (int) $customLimits . ' limit(s) changed from the defaults.' : 'All limits are at their defaults.' ?></p>
        <p class="mt-3"><a class="btn btn-secondary btn-sm" href="/admin/security/rate-limits">Review limits</a></p>
    </div>
</section>

<section class="grid gap-4 lg:grid-cols-2" aria-label="Sign-in activity">
    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">Addresses with the most failed sign-ins (24 h)</h2>
        <?php if ($top === []): ?>
            <p class="text-sm text-slate-500">No failed sign-ins in the last 24 hours.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="data" aria-label="Top failing addresses">
                <thead><tr><th>Address</th><th>Failures</th><th>Emails tried</th><th>Last</th><?php if ($canManage): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
                <tbody>
                <?php foreach ($top as $t): $ip = $ipText($t['ip_address']); ?>
                    <tr>
                        <td class="font-mono text-xs"><?= e($ip) ?><?= $ip === $yourIp ? ' <span class="text-slate-400">(you)</span>' : '' ?></td>
                        <td><?= number_format((int) $t['failures']) ?></td>
                        <td><?= number_format((int) $t['emails']) ?></td>
                        <td class="text-xs text-slate-500"><?= e(substr((string) $t['last_at'], 0, 16)) ?></td>
                        <?php if ($canManage): ?>
                            <td class="text-right">
                                <?php if ($ip !== '—' && $ip !== $yourIp): ?>
                                    <form method="post" action="/admin/security/ip-rules" data-confirm="Block <?= e_attr($ip) ?> for 24 hours?"><?= csrf_field() ?>
                                        <input type="hidden" name="effect" value="block"><input type="hidden" name="cidr" value="<?= e_attr($ip) ?>"><input type="hidden" name="minutes" value="1440"><input type="hidden" name="note" value="Blocked from the failed sign-in list">
                                        <button type="submit" class="btn btn-ghost btn-sm text-red-600">Block 24 h</button>
                                    </form>
                                <?php endif ?>
                            </td>
                        <?php endif ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        <?php endif ?>
    </div>

    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">Latest failed sign-ins</h2>
        <?php if ($recent === []): ?>
            <p class="text-sm text-slate-500">Nothing to show.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="data" aria-label="Latest failed sign-ins">
                <thead><tr><th>When (UTC)</th><th>Email</th><th>Address</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $a): ?>
                    <tr><td class="text-xs text-slate-500"><?= e(substr((string) $a['attempted_at'], 0, 16)) ?></td><td class="text-sm"><?= e((string) $a['email']) ?></td><td class="font-mono text-xs"><?= e($ipText($a['ip_address'])) ?></td></tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        <?php endif ?>
    </div>
</section>
<?php $this->stop(); ?>
