<?php
/** @var array<string,string> $fields @var int $maxRows @var int $maxKb */
$this->layout('layouts.app', ['title' => 'Import leads', 'currentPath' => '/leads']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Import leads',
    'breadcrumbs' => [['label' => 'Leads', 'href' => '/leads'], ['label' => 'Import']],
]) ?>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <form method="post" action="/leads/import" enctype="multipart/form-data" class="card card-body" data-once>
            <?= csrf_field() ?>

            <label class="form-label" for="file">CSV file</label>
            <input class="form-input" type="file" id="file" name="file" accept=".csv,text/csv" required>
            <?php if ($err = error('file')): ?>
                <p class="form-error"><?= e($err) ?></p>
            <?php else: ?>
                <p class="form-hint">
                    Up to <?= number_format($maxRows) ?> data rows, <?= number_format($maxKb) ?> KB. The first row
                    must be column headers — we'll suggest a mapping on the next screen and you can adjust it.
                </p>
            <?php endif ?>

            <button type="submit" class="btn btn-primary mt-4">Upload &amp; preview</button>
        </form>
    </div>

    <?= component('card', [
        'title' => 'Recognised columns',
        'body' => '<p class="mb-2 text-sm text-slate-600">Name any column header something close to these and it will '
            . 'usually auto-map. You can always fix the mapping by hand on the next screen.</p>'
            . '<ul class="space-y-1 text-sm text-slate-600">'
            . implode('', array_map(static fn ($f, $l) => '<li><code class="text-xs">' . e($f) . '</code> — ' . e($l) . '</li>', array_keys($fields), $fields))
            . '</ul>',
    ]) ?>
</div>
<?php $this->stop(); ?>
