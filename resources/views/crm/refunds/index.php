<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query @var array<string,int> $counts */
$this->layout('layouts.app', ['title' => 'Refunds', 'currentPath' => '/refunds']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'rejected' => 'red'];
?>
<?= component('page-header', [
    'title' => 'Refunds',
    'subtitle' => number_format($page->total) . ' matching',
    'actions' => can('reports.finance.view') ? '<a href="/reports/refunds-register" class="btn btn-secondary btn-sm">Report / export</a>' : '',
]) ?>

<div class="mb-4 grid gap-3 sm:grid-cols-4">
    <?php foreach ($color as $s => $c): ?>
        <a href="/refunds?<?= e_attr(http_build_query(['status' => $s])) ?>" class="card card-body block hover:border-brand-300">
            <p class="text-xs uppercase tracking-wide text-slate-500"><?= e(ucfirst($s)) ?></p>
            <p class="mt-1 text-2xl font-semibold text-slate-900"><?= (int) ($counts[$s] ?? 0) ?></p>
        </a>
    <?php endforeach ?>
</div>

<form method="get" action="/refunds" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="RF-…, PAY-… or customer">
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
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/refunds" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No refunds match these filters' : 'No refunds yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Request a refund from a payment’s page.',
    ])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Refund</th><th>Customer</th><th>Payment</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($page->items as $r): /** @var \App\Models\Refund $r */ ?>
                <tr>
                    <td><a href="/refunds/<?= e_attr($r->publicId) ?>" class="font-mono text-sm font-medium text-slate-900"><?= e($r->refundNumber) ?></a></td>
                    <td class="text-slate-700"><?= e($r->customerName) ?></td>
                    <td class="font-mono text-xs"><a href="/payments/<?= e_attr($r->paymentPublicId) ?>" class="text-brand-600"><?= e($r->paymentNumber) ?></a></td>
                    <td class="font-mono text-xs text-slate-500"><?= e($r->invoiceNumber ?? 'credit') ?></td>
                    <td class="whitespace-nowrap font-medium text-slate-800"><?= e($r->money()) ?></td>
                    <td class="text-slate-600"><?= e($r->methodLabel()) ?></td>
                    <td><?= component('badge', ['label' => $r->statusLabel(), 'color' => $color[$r->status] ?? 'slate']) ?></td>
                    <td class="text-right"><a href="/refunds/<?= e_attr($r->publicId) ?>" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/refunds', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
