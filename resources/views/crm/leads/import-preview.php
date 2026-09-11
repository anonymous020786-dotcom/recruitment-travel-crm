<?php
/**
 * @var \App\Models\ImportBatch $batch
 * @var list<array{id:int,row_number:int,raw_json:string}> $sample
 * @var array<string,string> $fields
 */
$this->layout('layouts.app', ['title' => 'Import leads · mapping', 'currentPath' => '/leads']);
$this->start('content');

$headers = $batch->headers !== [] ? $batch->headers : array_fill(0, 20, '');
?>
<?= component('page-header', [
    'title' => 'Check the column mapping',
    'subtitle' => $batch->originalName . ' · ' . number_format($batch->totalRows) . ' row(s)',
    'breadcrumbs' => [['label' => 'Leads', 'href' => '/leads'], ['label' => 'Import', 'href' => '/leads/import'], ['label' => 'Mapping']],
]) ?>

<?php if ($err = error('mapping')): ?>
    <div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $err]) ?></div>
<?php endif ?>

<form method="post" action="/leads/import/<?= e_attr($batch->publicId) ?>/confirm" data-once
      data-confirm="Import <?= (int) $batch->totalRows ?> row(s) into leads? This can take a moment and can't be undone from the UI.">
    <?= csrf_field() ?>

    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>CSV column</th><th>Maps to</th><th>Sample value</th></tr></thead>
                <tbody>
                <?php foreach ($headers as $i => $header): ?>
                    <?php
                    $selected = $batch->mapping[(string) $i] ?? '';
                    $firstSample = '';
                    foreach ($sample as $s) {
                        $raw = json_decode($s['raw_json'], true);
                        if (is_array($raw) && isset($raw[(string) $i]) && trim((string) $raw[(string) $i]) !== '') {
                            $firstSample = (string) $raw[(string) $i];
                            break;
                        }
                    }
                    ?>
                    <tr>
                        <td class="font-medium text-slate-900"><?= e($header !== '' ? $header : 'Column ' . ((int) $i + 1)) ?></td>
                        <td>
                            <select name="field[<?= (int) $i ?>]" aria-label="Maps to, for column <?= e_attr($header !== '' ? $header : (string) ((int) $i + 1)) ?>" class="form-select">
                                <option value="">— Ignore —</option>
                                <?php foreach ($fields as $key => $label): ?>
                                    <option value="<?= e_attr($key) ?>" <?= $selected === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach ?>
                            </select>
                        </td>
                        <td class="max-w-xs truncate text-slate-500"><?= e($firstSample) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-body mt-4">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="import_duplicates" value="1">
            Import rows even if they look like an existing lead (same phone or email). Off by default — likely
            duplicates are skipped and listed in the report.
        </label>
        <button type="submit" class="btn btn-primary mt-4">Import <?= number_format($batch->totalRows) ?> row(s)</button>
        <a href="/leads/import" class="btn btn-ghost mt-4">Start over</a>
    </div>
</form>

<?php if ($sample !== []): ?>
    <div class="card mt-4">
        <div class="card-body"><p class="text-sm font-medium text-slate-700">First rows, as read from the file</p></div>
        <div class="table-wrap">
            <table class="data">
                <thead><tr>
                    <?php foreach ($headers as $i => $header): ?>
                        <th><?= e($header !== '' ? $header : 'Col ' . ((int) $i + 1)) ?></th>
                    <?php endforeach ?>
                </tr></thead>
                <tbody>
                <?php foreach ($sample as $s): $raw = json_decode($s['raw_json'], true); $raw = is_array($raw) ? $raw : []; ?>
                    <tr>
                        <?php foreach ($headers as $i => $header): ?>
                            <td class="max-w-xs truncate text-slate-600"><?= e((string) ($raw[(string) $i] ?? '')) ?></td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
