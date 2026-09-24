<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query @var array<string,int> $counts */
$this->layout('layouts.app', ['title' => 'Website enquiries', 'currentPath' => '/enquiries']);
$this->start('content');

$color = ['new' => 'amber', 'reviewed' => 'blue', 'converted' => 'green', 'spam' => 'slate'];
$types = ['contact' => 'Contact form', 'job_apply' => 'Job application', 'travel_enquiry' => 'Package enquiry'];
$hasFilters = $query->hasSearch() || ($query->filters !== [] && $query->filter('status') !== 'new');
?>
<?= component('page-header', ['title' => 'Website enquiries', 'subtitle' => 'Messages, job applications and package enquiries from the public site. ' . number_format($page->total) . ' matching.']) ?>

<div class="mb-4 grid gap-3 sm:grid-cols-4">
    <?php foreach ($color as $s => $c): ?>
        <a href="/enquiries?<?= e_attr(http_build_query(['status' => $s])) ?>" class="card card-body block hover:border-brand-300">
            <p class="text-xs uppercase tracking-wide text-slate-500"><?= e(ucfirst($s)) ?></p>
            <p class="mt-1 text-2xl font-semibold text-slate-900"><?= (int) ($counts[$s] ?? 0) ?></p>
        </a>
    <?php endforeach ?>
</div>

<form method="get" action="/enquiries" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Name, phone or email">
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
        <label class="form-label" for="type">Type</label>
        <select class="form-select" id="type" name="type">
            <option value="">Any</option>
            <?php foreach ($types as $k => $label): ?>
                <option value="<?= e_attr($k) ?>" <?= $query->filter('type') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/enquiries" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No enquiries match these filters' : 'Nothing waiting',
        'message' => $hasFilters ? 'Try widening your search.' : 'New enquiries from the public site will appear here.',
    ])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Website enquiries">
            <thead><tr><th>Received</th><th>From</th><th>About</th><th>Message</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($page->items as $e): ?>
                <tr>
                    <td class="whitespace-nowrap text-xs text-slate-600"><?= e(date('d M Y H:i', strtotime((string) $e['created_at'] . ' UTC'))) ?></td>
                    <td>
                        <a href="/enquiries/<?= (int) $e['id'] ?>" class="font-medium text-slate-900"><?= e($e['name']) ?></a>
                        <p class="text-xs text-slate-500"><?= e($e['phone']) ?></p>
                    </td>
                    <td class="text-sm text-slate-700">
                        <?= e($types[$e['type']] ?? $e['type']) ?>
                        <?php if (!empty($e['job_title'])): ?><p class="text-xs text-slate-500"><?= e($e['job_title']) ?></p><?php endif ?>
                        <?php if (!empty($e['package_name'])): ?><p class="text-xs text-slate-500"><?= e($e['package_name']) ?></p><?php endif ?>
                    </td>
                    <td class="max-w-xs truncate text-sm text-slate-600" title="<?= e_attr((string) $e['message']) ?>"><?= e((string) $e['message']) ?></td>
                    <td><?= component('badge', ['label' => ucfirst((string) $e['status']), 'color' => $color[$e['status']] ?? 'slate', 'dot' => true]) ?></td>
                    <td class="text-right"><a class="btn btn-secondary btn-sm" href="/enquiries/<?= (int) $e['id'] ?>">Open<span class="sr-only"> enquiry from <?= e($e['name']) ?></span></a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= component('pagination', ['page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total, 'baseUrl' => '/enquiries', 'query' => $query->toQueryArray()]) ?>
<?php endif ?>
<?php $this->stop(); ?>
