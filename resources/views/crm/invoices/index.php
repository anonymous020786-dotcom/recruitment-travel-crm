<?php
/**
 * @var \App\Support\Page $page @var \App\Support\ListQuery $query
 * @var list<array{currency:string,billed:string,collected:string,outstanding:string,overdue:string}> $summary @var bool $canCreate
 */
$this->layout('layouts.app', ['title' => 'Invoices', 'currentPath' => '/invoices']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['draft' => 'slate', 'issued' => 'blue', 'partially_paid' => 'amber', 'paid' => 'green', 'void' => 'red'];
$money = static fn (string $cur, string $v): string => e($cur . ' ' . number_format((float) $v, 2));
?>
<?= component('page-header', [
    'title' => 'Invoices',
    'subtitle' => number_format($page->total) . ' matching',
    'actions' => '<a href="/invoices/aging" class="btn btn-secondary btn-sm">Ageing</a> '
        . (can('reports.finance.view') ? '<a href="/reports/invoices-register" class="btn btn-secondary btn-sm">Report / export</a> ' : '') . ($canCreate ? '<a href="/invoices/create" class="btn btn-primary btn-sm">New invoice</a>' : ''),
]) ?>

<?php if ($summary !== []): ?>
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($summary as $s): ?>
            <div class="card card-body">
                <p class="text-xs uppercase tracking-wide text-slate-500">Issued invoices · <?= e($s['currency']) ?></p>
                <p class="mt-1 text-sm text-slate-600">Billed <span class="font-medium text-slate-900"><?= $money($s['currency'], $s['billed']) ?></span>
                    · collected <span class="font-medium text-slate-900"><?= $money($s['currency'], $s['collected']) ?></span></p>
                <p class="mt-1 text-sm text-slate-600">Outstanding <span class="font-semibold text-slate-900"><?= $money($s['currency'], $s['outstanding']) ?></span>
                    <?php if ((float) $s['overdue'] > 0): ?>· <a href="/invoices?due=overdue" class="font-medium text-red-600">overdue <?= $money($s['currency'], $s['overdue']) ?></a><?php endif ?></p>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>

<form method="get" action="/invoices" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="INV-…, customer or phone">
    </div>
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach (array_keys($color) as $s): ?>
                <option value="<?= e_attr($s) ?>" <?= $query->filter('status') === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="type">For</label>
        <select class="form-select" id="type" name="type">
            <?php foreach (['' => 'Any', 'application' => 'Recruitment', 'tour_booking' => 'Tours', 'other' => 'Other'] as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= (string) $query->filter('type') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="due">Due</label>
        <select class="form-select" id="due" name="due">
            <?php foreach (['' => 'Any', 'overdue' => 'Overdue'] as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= (string) $query->filter('due') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/invoices" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No invoices match these filters' : 'No invoices yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Raise an invoice for an application or a tour booking.',
    ])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <?php
                $col = static function (string $key, string $label) use ($query): string {
                    $active = $query->sort === $key;
                    $dir = $active && $query->direction === 'asc' ? 'desc' : 'asc';
                    $q = array_merge($query->toQueryArray(), ['sort' => $key, 'dir' => $dir]);
                    $arrow = $active ? ($query->direction === 'asc' ? ' ↑' : ' ↓') : '';
                    return '<th><a class="hover:text-slate-800" href="/invoices?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('number', 'Invoice');
                ?>
                <?= $col('customer', 'Customer') ?>
                <th>For</th>
                <?= $col('due_on', 'Due') ?>
                <?= $col('total', 'Total') ?>
                <th>Outstanding</th>
                <?= $col('status', 'Status') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $i): /** @var \App\Models\Invoice $i */ ?>
                <tr>
                    <td><a href="/invoices/<?= e_attr($i->publicId) ?>" class="font-mono text-sm font-medium text-slate-900"><?= e($i->invoiceNumber) ?></a></td>
                    <td class="text-slate-700"><?= e($i->customerName) ?></td>
                    <td class="text-xs text-slate-500"><?= e($i->typeLabel()) ?><?= $i->referenceNumber ? ' · <span class="font-mono">' . e($i->referenceNumber) . '</span>' : '' ?></td>
                    <td class="whitespace-nowrap <?= $i->isOverdue() ? 'font-medium text-red-600' : 'text-slate-600' ?>"><?= e($i->dueOn ?? '—') ?></td>
                    <td class="whitespace-nowrap text-slate-700"><?= e($i->money($i->grandTotal)) ?></td>
                    <td class="whitespace-nowrap text-slate-700"><?= $i->status === 'draft' || $i->status === 'void' ? '—' : e($i->money($i->outstanding())) ?></td>
                    <td><?= component('badge', ['label' => $i->statusLabel(), 'color' => $color[$i->status] ?? 'slate']) ?>
                        <?php if ($i->isOverdue()): ?><?= component('badge', ['label' => 'Overdue', 'color' => 'red']) ?><?php endif ?></td>
                    <td class="text-right"><a href="/invoices/<?= e_attr($i->publicId) ?>" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/invoices', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
