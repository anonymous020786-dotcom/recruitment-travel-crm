<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query @var bool $canCreate */
$this->layout('layouts.app', ['title' => 'Tour packages', 'currentPath' => '/tours/packages']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['draft' => 'slate', 'active' => 'green', 'archived' => 'amber'];
?>
<?= component('page-header', [
    'title' => 'Tour packages',
    'subtitle' => number_format($page->total) . ' total',
    'actions' => $canCreate ? '<a href="/tours/packages/create" class="btn btn-primary btn-sm">New package</a>' : '',
]) ?>

<form method="get" action="/tours/packages" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Name, destination or departure point">
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
        <label class="form-label" for="visibility">Public site</label>
        <select class="form-select" id="visibility" name="visibility">
            <?php foreach (['' => 'Any', 'public' => 'Published', 'private' => 'Not published'] as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= (string) $query->filter('visibility') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/tours/packages" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No packages match these filters' : 'No tour packages yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Create the first package to start selling tours.',
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
                    return '<th><a class="hover:text-slate-800" href="/tours/packages?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('name', 'Package');
                ?>
                <?= $col('destination', 'Destination') ?>
                <th>Duration</th>
                <?= $col('price', 'Price') ?>
                <?= $col('status', 'Status') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $p): /** @var \App\Models\TourPackage $p */ ?>
                <tr>
                    <td><a href="/tours/packages/<?= e_attr($p->publicId) ?>" class="font-medium text-slate-900"><?= e($p->name) ?></a>
                        <span class="text-xs text-slate-400"><?= (int) $p->itemCount ?> itinerary line<?= $p->itemCount === 1 ? '' : 's' ?></span></td>
                    <td class="text-slate-600"><?= e($p->destination) ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= e($p->durationLabel()) ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= e($p->priceLabel()) ?></td>
                    <td><?= component('badge', ['label' => $p->statusLabel(), 'color' => $color[$p->status] ?? 'slate']) ?>
                        <?php if ($p->isPublic): ?><?= component('badge', ['label' => 'Public', 'color' => 'emerald']) ?><?php endif ?></td>
                    <td class="text-right"><a href="/tours/packages/<?= e_attr($p->publicId) ?>" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/tours/packages', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
