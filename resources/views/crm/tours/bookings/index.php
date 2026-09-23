<?php
/**
 * @var \App\Support\Page $page @var \App\Support\ListQuery $query @var array<string,int> $counts
 * @var array<string,string> $packages @var bool $canCreate
 */
$this->layout('layouts.app', ['title' => 'Tour bookings', 'currentPath' => '/tours/bookings']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['inquiry' => 'slate', 'quoted' => 'amber', 'confirmed' => 'green', 'travelling' => 'blue', 'completed' => 'indigo', 'cancelled' => 'red'];
?>
<?= component('page-header', [
    'title' => 'Tour bookings',
    'subtitle' => number_format($page->total) . ' matching',
    'actions' => $canCreate ? '<a href="/tours/bookings/create" class="btn btn-primary btn-sm">New booking</a>' : '',
]) ?>

<div class="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
    <?php foreach ($color as $s => $c): ?>
        <a href="/tours/bookings?<?= e_attr(http_build_query(['status' => $s])) ?>" class="card card-body block hover:border-brand-300">
            <p class="text-xs uppercase tracking-wide text-slate-500"><?= e(ucfirst($s)) ?></p>
            <p class="mt-1 text-2xl font-semibold text-slate-900"><?= (int) ($counts[$s] ?? 0) ?></p>
        </a>
    <?php endforeach ?>
</div>

<form method="get" action="/tours/bookings" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Customer, phone, TB-… or package">
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
        <label class="form-label" for="package">Package</label>
        <select class="form-select" id="package" name="package">
            <option value="">Any</option>
            <?php foreach ($packages as $publicId => $label): ?>
                <option value="<?= e_attr((string) $publicId) ?>" <?= $query->filter('package') === (string) $publicId ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="when">Travel</label>
        <select class="form-select" id="when" name="when">
            <?php foreach (['' => 'Any time', 'upcoming' => 'Upcoming (open bookings)', 'undated' => 'No date yet'] as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= (string) $query->filter('when') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/tours/bookings" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No bookings match these filters' : 'No tour bookings yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Create the first booking from an inquiry.',
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
                    return '<th><a class="hover:text-slate-800" href="/tours/bookings?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('customer', 'Customer');
                ?>
                <th>Trip</th>
                <?= $col('travel_date', 'Travel') ?>
                <th>Travellers</th>
                <?= $col('amount', 'Amount') ?>
                <?= $col('status', 'Status') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $b): /** @var \App\Models\TourBooking $b */ ?>
                <tr>
                    <td><a href="/tours/bookings/<?= e_attr($b->publicId) ?>" class="font-medium text-slate-900"><?= e($b->customerName) ?></a>
                        <span class="font-mono text-xs text-slate-400"><?= e($b->bookingNumber) ?></span></td>
                    <td class="text-slate-600"><?= e($b->tripLabel()) ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= e($b->travelDate ?? '—') ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= (int) $b->travellers() ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= (float) $b->totalAmount > 0 ? e($b->amountLabel()) : '—' ?></td>
                    <td><?= component('badge', ['label' => $b->statusLabel(), 'color' => $color[$b->status] ?? 'slate']) ?></td>
                    <td class="text-right"><a href="/tours/bookings/<?= e_attr($b->publicId) ?>" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/tours/bookings', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
