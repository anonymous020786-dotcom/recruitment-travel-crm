<?php
/**
 * @var list<array<string,mixed>> $jobs @var int $unhealthy
 * @var array{at:string,checked:int,failed:int,findings:list<array<string,string>>}|null $integrity
 */
$this->layout('layouts.app', ['title' => 'Scheduled jobs', 'currentPath' => '/admin/cron']);
$this->start('content');

$color = ['ok' => 'green', 'running' => 'blue', 'never' => 'slate', 'disabled' => 'slate', 'late' => 'amber', 'failing' => 'red', 'stuck' => 'red'];
?>
<?= component('page-header', ['title' => 'Scheduled jobs', 'subtitle' => 'Times are UTC. Administrators are notified when a job fails, gets stuck or runs late.']) ?>

<div class="mb-4 grid gap-3 sm:grid-cols-3">
    <?= component('stat', ['label' => 'Jobs', 'value' => count($jobs)]) ?>
    <?= component('stat', ['label' => 'Needing attention', 'value' => $unhealthy, 'hint' => $unhealthy === 0 ? 'All healthy' : 'Failing, stuck or late']) ?>
    <?= component('stat', ['label' => 'Last data check', 'value' => $integrity === null ? 'Never run' : ($integrity['failed'] === 0 ? 'Clean' : $integrity['failed'] . ' failed'), 'hint' => $integrity['at'] ?? 'Runs daily']) ?>
</div>

<div class="table-wrap mb-6">
    <table class="data">
        <thead><tr><th>Job</th><th>State</th><th>Schedule</th><th>Last run</th><th>Result</th><th>Last success</th><th>Next due</th><th>Failures 24h</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
            <tr>
                <td><a href="/admin/cron/<?= e_attr($j['job']) ?>" class="font-mono text-sm font-medium text-slate-900"><?= e($j['job']) ?></a></td>
                <td><?= component('badge', ['label' => $j['state'], 'color' => $color[$j['state']] ?? 'slate']) ?></td>
                <td class="font-mono text-xs text-slate-500"><?= e($j['schedule']) ?></td>
                <td class="whitespace-nowrap text-xs text-slate-600"><?= e($j['last_started'] ?? '—') ?></td>
                <td class="text-xs text-slate-600">
                    <?= e($j['last_status'] ?? '—') ?><?= $j['last_items'] !== null ? ' · ' . (int) $j['last_items'] . ' item' . ($j['last_items'] === 1 ? '' : 's') : '' ?>
                    <?php if (!empty($j['last_message'])): ?><span class="block max-w-xs truncate text-red-700" title="<?= e_attr($j['last_message']) ?>"><?= e($j['last_message']) ?></span><?php endif ?>
                </td>
                <td class="whitespace-nowrap text-xs text-slate-600"><?= e($j['last_success'] ?? '—') ?></td>
                <td class="whitespace-nowrap text-xs text-slate-600"><?= e($j['next_due'] ?? '—') ?></td>
                <td class="text-xs <?= $j['failures_24h'] > 0 ? 'font-medium text-red-700' : 'text-slate-500' ?>"><?= (int) $j['failures_24h'] ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Data integrity</h2>
<?php if ($integrity === null): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No check has run yet', 'message' => 'The integrity-check job runs daily.'])]) ?>
<?php elseif ($integrity['findings'] === []): ?>
    <div class="card card-body text-sm text-slate-700">All <?= (int) $integrity['checked'] ?> checks passed at <?= e($integrity['at']) ?> UTC.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Check</th><th>Record</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($integrity['findings'] as $f): ?>
                <tr><td class="text-sm"><?= e($f['title']) ?></td><td class="font-mono text-xs"><?= e($f['ref']) ?></td><td class="text-xs text-slate-600"><?= e($f['detail']) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-500">Checked at <?= e($integrity['at']) ?> UTC. Findings are capped per check.</p>
<?php endif ?>
<?php $this->stop(); ?>
