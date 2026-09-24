<?php
/** @var array<string,mixed> $job @var list<array<string,mixed>> $runs */
$this->layout('layouts.app', ['title' => $job['job'], 'currentPath' => '/admin/cron']);
$this->start('content');

$color = ['success' => 'green', 'running' => 'blue', 'failed' => 'red'];
?>
<?= component('page-header', [
    'title' => $job['job'],
    'subtitle' => $job['script'] . ' · ' . $job['schedule'] . ' (UTC)',
    'breadcrumbs' => [['label' => 'Scheduled jobs', 'href' => '/admin/cron'], ['label' => $job['job']]],
]) ?>

<div class="mb-4 grid gap-3 sm:grid-cols-3">
    <?= component('stat', ['label' => 'State', 'value' => ucfirst($job['state'])]) ?>
    <?= component('stat', ['label' => 'Last success', 'value' => $job['last_success'] ?? 'Never']) ?>
    <?= component('stat', ['label' => 'Next due', 'value' => $job['next_due'] ?? '—']) ?>
</div>

<?php if ($runs === []): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No runs recorded', 'message' => 'This job has not run yet.'])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Started</th><th>Status</th><th>Duration</th><th>Items</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
                <tr>
                    <td class="whitespace-nowrap text-xs text-slate-600"><?= e($r['started_at']) ?></td>
                    <td><?= component('badge', ['label' => $r['status'], 'color' => $color[$r['status']] ?? 'slate']) ?></td>
                    <td class="text-xs text-slate-600"><?= $r['seconds'] !== null ? (int) $r['seconds'] . 's' : '—' ?></td>
                    <td class="text-xs text-slate-600"><?= (int) $r['items_processed'] ?></td>
                    <td class="text-xs text-red-700"><?= e($r['message'] ?? '') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-500">Latest <?= count($runs) ?> runs; older runs are pruned after 30 days.</p>
<?php endif ?>
<?php $this->stop(); ?>
