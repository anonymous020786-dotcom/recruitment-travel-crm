<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query @var array<string,string> $countries @var bool $canCreate */
$this->layout('layouts.app', ['title' => 'Employers', 'currentPath' => '/employers']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$statusColor = ['active' => 'green', 'prospect' => 'blue', 'suspended' => 'amber', 'blacklisted' => 'red', 'inactive' => 'slate'];
?>
<?= component('page-header', [
    'title' => 'Employers',
    'subtitle' => number_format($page->total) . ' total',
    'actions' => $canCreate ? '<a href="/employers/create" class="btn btn-primary btn-sm">New employer</a>' : '',
]) ?>

<form method="get" action="/employers" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="lg:col-span-2">
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Company, industry or EMP-…">
    </div>
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach (['prospect', 'active', 'suspended', 'blacklisted', 'inactive'] as $s): ?>
                <option value="<?= $s ?>" <?= $query->filter('status') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="country">Country</label>
        <select class="form-select" id="country" name="country">
            <option value="">Any</option>
            <?php foreach ($countries as $code => $name): ?>
                <option value="<?= e_attr($code) ?>" <?= $query->filter('country') === $code ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/employers" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No employers match these filters' : 'No employers yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Add the first recruiting client.',
        'action' => $hasFilters ? '<a href="/employers" class="btn btn-secondary">Clear filters</a>' : ($canCreate ? '<a href="/employers/create" class="btn btn-primary">New employer</a>' : ''),
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
                    return '<th><a class="hover:text-slate-800" href="/employers?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('name', 'Company');
                ?>
                <th>Employer #</th>
                <?= $col('country', 'Country') ?>
                <th>Industry</th>
                <?= $col('status', 'Status') ?>
                <th>Account owner</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $e): /** @var \App\Models\Employer $e */ ?>
                <tr>
                    <td><a href="/employers/<?= e_attr($e->publicId) ?>" class="font-medium text-slate-900"><?= e($e->companyName) ?></a></td>
                    <td class="font-mono text-xs"><?= e($e->employerNumber) ?></td>
                    <td><?= e($countries[$e->country] ?? $e->country) ?></td>
                    <td class="text-slate-600"><?= e($e->industry ?? '—') ?></td>
                    <td><?= component('badge', ['label' => $e->statusLabel(), 'color' => $statusColor[$e->status] ?? 'slate']) ?></td>
                    <td class="text-slate-600"><?= e($e->accountOwnerName ?? '—') ?></td>
                    <td class="text-right"><a href="/employers/<?= e_attr($e->publicId) ?>" class="btn btn-ghost btn-sm">View</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/employers', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
