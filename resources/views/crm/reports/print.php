<?php
/**
 * Printable report: no navigation, plain table, print button hidden on paper.
 * @var string $key @var array{title:string,description:string,filter:string,columns:list<string>,numeric:list<int>} $def
 * @var array{from:string,to:string,days:int} $filters @var list<list<string|int>> $rows @var bool $truncated @var int $limit @var string $generatedBy
 */
$period = match ($def['filter']) {
    'range' => $filters['from'] . ' to ' . $filters['to'],
    'days'  => 'expiring within ' . $filters['days'] . ' days',
    default => 'as of ' . gmdate('Y-m-d'),
};
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= e($def['title']) ?> — <?= e($period) ?></title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #0f172a; margin: 24px; font-size: 13px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        th { background: #f8fafc; font-weight: 600; }
        .r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .bar { text-align: right; margin-bottom: 8px; }
        button { font: inherit; padding: 6px 14px; }
        @media print { .bar { display: none; } body { margin: 0; } th { background: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Print</button></div>
<h1><?= e($def['title']) ?></h1>
<p class="muted"><?= e($period) ?> · generated <?= e(gmdate('Y-m-d H:i')) ?> UTC by <?= e($generatedBy) ?></p>
<table>
    <thead><tr><?php foreach ($def['columns'] as $i => $col): ?><th class="<?= in_array($i, $def['numeric'], true) ? 'r' : '' ?>"><?= e($col) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
        <tr><td colspan="<?= count($def['columns']) ?>" class="muted">No rows.</td></tr>
    <?php endif ?>
    <?php foreach ($rows as $row): ?>
        <tr><?php foreach ($row as $i => $cell): ?><td class="<?= in_array($i, $def['numeric'], true) ? 'r' : '' ?>"><?= e((string) $cell) ?></td><?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
</table>
<?php if ($truncated): ?><p class="muted">Only the first <?= (int) $limit ?> rows are shown. Narrow the dates or download the CSV for the full list.</p><?php endif ?>
</body>
</html>
