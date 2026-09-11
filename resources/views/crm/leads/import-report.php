<?php
/** @var \App\Models\ImportBatch $batch */
$this->layout('layouts.app', ['title' => 'Import report', 'currentPath' => '/leads']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Import report',
    'subtitle' => $batch->originalName,
    'breadcrumbs' => [['label' => 'Leads', 'href' => '/leads'], ['label' => 'Import', 'href' => '/leads/import'], ['label' => 'Report']],
]) ?>

<?php if (!$batch->isDone()): ?>
    <?= component('alert', ['type' => 'info', 'message' => 'This import is still ' . e($batch->status) . '. Refresh in a moment.']) ?>
<?php else: ?>
    <div class="grid gap-4 sm:grid-cols-3">
        <?= component('stat', ['label' => 'Imported', 'value' => number_format($batch->importedRows)]) ?>
        <?= component('stat', ['label' => 'Skipped (likely duplicate)', 'value' => number_format($batch->skippedRows)]) ?>
        <?= component('stat', ['label' => 'Failed', 'value' => number_format($batch->failedRows)]) ?>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="/leads" class="btn btn-primary">Back to leads</a>
        <?php if ($batch->reportPath !== null): ?>
            <a href="/leads/import/<?= e_attr($batch->publicId) ?>/report/download" class="btn btn-secondary">Download row-by-row report (.csv)</a>
        <?php endif ?>
        <a href="/leads/import" class="btn btn-ghost">Import another file</a>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
