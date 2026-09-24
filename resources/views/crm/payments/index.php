<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query */
$this->layout('layouts.app', ['title' => 'Payments', 'currentPath' => '/payments']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['recorded' => 'green', 'reversed' => 'red'];
?>
<?= component('page-header', ['title' => 'Payments', 'subtitle' => number_format($page->total) . ' matching']) ?>

<form method="get" action="/payments" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="PAY-…, RCT-…, customer or reference">
    </div>
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach (array_keys($color) as $s): ?>
                <option value="<?= e_attr($s) ?>" <?= $query->filter('status') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="method">Method</label>
        <select class="form-select" id="method" name="method">
            <option value="">Any</option>
            <?php foreach (\App\Models\Payment::METHODS as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= $query->filter('method') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="credit">Credit</label>
        <select class="form-select" id="credit" name="credit">
            <?php foreach (['' => 'Any', 'unallocated' => 'Has unallocated credit'] as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= (string) $query->filter('credit') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/payments" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No payments match these filters' : 'No payments yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Record a payment from an issued invoice.',
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
                    return '<th><a class="hover:text-slate-800" href="/payments?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('number', 'Payment');
                ?>
                <?= $col('customer', 'Customer') ?>
                <?= $col('paid_at', 'Received') ?>
                <th>Method</th>
                <?= $col('amount', 'Amount') ?>
                <th>Unallocated</th>
                <?= $col('status', 'Status') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $p): /** @var \App\Models\Payment $p */ ?>
                <tr>
                    <td><a href="/payments/<?= e_attr($p->publicId) ?>" class="font-mono text-sm font-medium text-slate-900"><?= e($p->paymentNumber) ?></a>
                        <span class="block font-mono text-xs text-slate-400"><?= e($p->receiptNumber) ?></span></td>
                    <td class="text-slate-700"><?= e($p->customerName) ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= e(substr($p->paidAt, 0, 16)) ?></td>
                    <td class="text-slate-600"><?= e($p->methodLabel()) ?><?= $p->reference ? '<span class="block font-mono text-xs text-slate-400">' . e($p->reference) . '</span>' : '' ?></td>
                    <td class="whitespace-nowrap font-medium text-slate-800"><?= e($p->money($p->amount)) ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= $p->unallocatedMinor() > 0 ? '<span class="font-medium text-amber-700">' . e($p->money($p->unallocated())) . '</span>' : '—' ?></td>
                    <td><?= component('badge', ['label' => $p->statusLabel(), 'color' => $color[$p->status] ?? 'slate']) ?></td>
                    <td class="text-right"><a href="/payments/<?= e_attr($p->publicId) ?>" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/payments', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
