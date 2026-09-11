<?php
/** @var list<\App\Models\ExportJob> $jobs */
$this->layout('layouts.app', ['title' => 'My exports', 'currentPath' => '/leads']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'My exports',
    'subtitle' => 'Queued CSV exports you\'ve requested — generated in the background, kept for a few days.',
    'breadcrumbs' => [['label' => 'Leads', 'href' => '/leads'], ['label' => 'Exports']],
]) ?>

<?php if ($jobs === []): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => 'No exports yet',
        'message' => 'Export a leads list from the Leads page and it will show up here once it\'s ready.',
        'action' => '<a href="/leads" class="btn btn-primary">Go to leads</a>',
    ])]) ?>
<?php else: ?>
    <div class="card table-wrap">
        <table class="data">
            <thead><tr><th>Report</th><th>Requested</th><th>Status</th><th>Rows</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td class="capitalize"><?= e($job->report) ?></td>
                    <td class="whitespace-nowrap text-slate-500"><?= e(substr($job->createdAt, 0, 16)) ?></td>
                    <td>
                        <?= component('badge', match ($job->status) {
                            'completed' => $job->isExpired()
                                ? ['label' => 'Expired', 'color' => 'slate']
                                : ['label' => 'Ready', 'color' => 'emerald', 'dot' => true],
                            'failed' => ['label' => 'Failed', 'color' => 'rose'],
                            'processing' => ['label' => 'Processing…', 'color' => 'amber', 'dot' => true],
                            default => ['label' => 'Queued', 'color' => 'slate', 'dot' => true],
                        }) ?>
                    </td>
                    <td class="text-slate-600"><?= $job->rowCount !== null ? number_format($job->rowCount) : '—' ?></td>
                    <td class="text-right">
                        <?php if ($job->isReady()): ?>
                            <a href="/exports/<?= e_attr($job->publicId) ?>/download" class="btn btn-secondary btn-sm">Download</a>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-400">This page doesn't auto-refresh — reload it to check on a queued export.</p>
<?php endif ?>
<?php $this->stop(); ?>
