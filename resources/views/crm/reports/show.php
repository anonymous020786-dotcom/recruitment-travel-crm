<?php
/**
 * @var string $key @var array{title:string,group:string,description:string,filter:string,columns:list<string>,numeric:list<int>} $def
 * @var array{from:string,to:string,days:int} $filters @var list<list<string|int>> $rows @var bool $truncated @var int $limit @var bool $canExport
 */
$this->layout('layouts.app', ['title' => $def['title'], 'currentPath' => '/reports']);
$this->start('content');

$qs = match ($def['filter']) {
    'range' => ['from' => $filters['from'], 'to' => $filters['to']],
    'days'  => ['days' => $filters['days']],
    default => [],
};
$actions = '<a href="/reports/' . e_attr($key) . '?' . e_attr(http_build_query($qs + ['print' => 1])) . '" target="_blank" class="btn btn-secondary btn-sm">Print</a>';
if ($canExport) {
    $actions .= ' <a href="/reports/' . e_attr($key) . '/csv?' . e_attr(http_build_query($qs)) . '" class="btn btn-primary btn-sm">Download CSV</a>';
}
?>
<?= component('page-header', [
    'title' => $def['title'],
    'subtitle' => $def['description'],
    'breadcrumbs' => [['label' => 'Reports', 'href' => '/reports'], ['label' => $def['title']]],
    'actions' => $actions,
]) ?>

<?php if ($def['filter'] !== 'none'): ?>
<form method="get" action="/reports/<?= e_attr($key) ?>" class="card card-body mb-4 flex flex-wrap items-end gap-3">
    <?php if ($def['filter'] === 'range'): ?>
        <div><label class="form-label" for="from">From</label><input class="form-input" type="date" id="from" name="from" value="<?= e_attr($filters['from']) ?>"></div>
        <div><label class="form-label" for="to">To</label><input class="form-input" type="date" id="to" name="to" value="<?= e_attr($filters['to']) ?>"></div>
    <?php else: ?>
        <div><label class="form-label" for="days">Expiring within (days)</label><input class="form-input" type="number" id="days" name="days" min="1" max="365" value="<?= (int) $filters['days'] ?>"></div>
    <?php endif ?>
    <button type="submit" class="btn btn-primary">Run report</button>
</form>
<?php endif ?>

<?php if ($rows === []): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No rows', 'message' => 'Nothing matches these filters in the branches you can see.'])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <?php foreach ($def['columns'] as $i => $col): ?>
                    <th class="<?= in_array($i, $def['numeric'], true) ? 'text-right' : '' ?>"><?= e($col) ?></th>
                <?php endforeach ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $i => $cell): ?>
                        <td class="<?= in_array($i, $def['numeric'], true) ? 'whitespace-nowrap text-right tabular-nums' : '' ?>"><?= e((string) $cell) ?></td>
                    <?php endforeach ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-500">
        <?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?> shown<?php if ($truncated): ?> — <strong>the list is cut off at <?= (int) $limit ?> rows</strong>. <?= $canExport ? 'Download the CSV for the full report.' : 'Narrow the dates to see the rest.' ?><?php endif ?>.
    </p>
<?php endif ?>
<?php $this->stop(); ?>
