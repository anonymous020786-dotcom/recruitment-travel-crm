<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query */
$this->layout('layouts.app', ['title' => 'Leads', 'currentPath' => '/leads']);
$this->start('content');

$f = static fn (string $k, $d = '') => e_attr((string) ($query->filter($k) ?? $d));
$hasFilters = $query->hasSearch() || $query->filters !== [];
?>
<?= component('page-header', [
    'title' => 'Leads',
    'subtitle' => number_format($page->total) . ' total',
    'actions' => implode(' ', array_filter([
        can('leads.import') && can('imports.run') ? '<a href="/leads/import" class="btn btn-secondary">Import</a>' : '',
        (can('leads.export') && can('exports.run'))
            ? '<form method="post" action="/leads/export?' . e_attr(http_build_query($query->toQueryArray())) . '" class="inline">'
                . csrf_field() . '<button type="submit" class="btn btn-secondary">Export' . ($hasFilters ? ' (filtered)' : '') . '</button></form>'
            : '',
        can('exports.run') ? '<a href="/exports" class="btn btn-ghost">My exports</a>' : '',
        can('leads.create') ? '<a href="/leads/create" class="btn btn-primary">New lead</a>' : '',
    ])),
]) ?>

<form method="get" action="/leads" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
    <div class="lg:col-span-2">
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>"
               placeholder="Name, phone, email or LEAD-…">
    </div>
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e_attr($s['key_name']) ?>" <?= $query->filter('status') === $s['key_name'] ? 'selected' : '' ?>>
                    <?= e($s['label']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="priority">Priority</label>
        <select class="form-select" id="priority" name="priority">
            <option value="">Any</option>
            <?php foreach (['urgent', 'high', 'medium', 'low'] as $p): ?>
                <option value="<?= $p ?>" <?= $query->filter('priority') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="assignee">Assignee</label>
        <select class="form-select" id="assignee" name="assignee">
            <option value="">Anyone</option>
            <option value="0" <?= $query->filter('assignee') === '0' ? 'selected' : '' ?>>Unassigned</option>
            <?php foreach ($assignees as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (string) $query->filter('assignee') === (string) $u['id'] ? 'selected' : '' ?>>
                    <?= e($u['name']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/leads" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No leads match these filters' : 'No leads yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Create your first lead to get started.',
        'action' => $hasFilters
            ? '<a href="/leads" class="btn btn-secondary">Clear filters</a>'
            : (can('leads.create') ? '<a href="/leads/create" class="btn btn-primary">New lead</a>' : ''),
    ])]) ?>
<?php else: ?>
    <form method="post" action="/leads/bulk/assign" data-confirm="Reassign the selected leads?">
        <?= csrf_field() ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <?php if (can('leads.assign')): ?><th class="w-8"><input type="checkbox" data-check-all aria-label="Select all"></th><?php endif ?>
                    <?php
                    $col = static function (string $key, string $label) use ($query): string {
                        $active = $query->sort === $key;
                        $dir = $active && $query->direction === 'asc' ? 'desc' : 'asc';
                        $q = array_merge($query->toQueryArray(), ['sort' => $key, 'dir' => $dir]);
                        $arrow = $active ? ($query->direction === 'asc' ? ' ↑' : ' ↓') : '';
                        return '<th><a class="hover:text-slate-800" href="/leads?' . e_attr(http_build_query($q)) . '">'
                            . e($label) . $arrow . '</a></th>';
                    };
                    echo $col('lead_number', 'Lead #');
                    echo $col('name', 'Name');
                    ?>
                    <th>Phone</th>
                    <?= $col('status', 'Status') ?>
                    <?= $col('priority', 'Priority') ?>
                    <th>Assignee</th>
                    <?= $col('created_at', 'Created') ?>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($page->items as $lead): /** @var \App\Models\Lead $lead */ ?>
                    <tr>
                        <?php if (can('leads.assign')): ?>
                            <td><input type="checkbox" name="lead_ids[]" value="<?= e_attr($lead->publicId) ?>" aria-label="Select <?= e_attr($lead->name) ?>"></td>
                        <?php endif ?>
                        <td class="font-mono text-xs"><a href="/leads/<?= e_attr($lead->publicId) ?>"><?= e($lead->leadNumber) ?></a></td>
                        <td>
                            <a href="/leads/<?= e_attr($lead->publicId) ?>" class="font-medium text-slate-900"><?= e($lead->name) ?></a>
                            <?php if ($lead->interestedCountry): ?><span class="ml-1 text-xs text-slate-400"><?= e($lead->interestedCountry) ?></span><?php endif ?>
                        </td>
                        <td class="whitespace-nowrap"><a href="tel:<?= e_attr($lead->phone) ?>" class="text-slate-600"><?= e($lead->phone) ?></a></td>
                        <td><?= component('badge', ['label' => $lead->statusLabel, 'color' => $lead->statusColor(), 'dot' => true]) ?></td>
                        <td><?= component('badge', ['label' => ucfirst($lead->priority), 'color' => $lead->priorityColor()]) ?></td>
                        <td class="text-slate-600"><?= e($lead->assignedToName ?? '—') ?></td>
                        <td class="whitespace-nowrap text-slate-500"><?= e(substr($lead->createdAt, 0, 10)) ?></td>
                        <td class="text-right">
                            <a href="/leads/<?= e_attr($lead->publicId) ?>" class="btn btn-ghost btn-sm">View</a>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <?php if (can('leads.assign')): ?>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm" data-bulk-bar hidden>
                <span class="text-slate-500"><span data-bulk-count>0</span> selected</span>
                <select name="assigned_to" aria-label="Reassign selected leads to" class="form-select max-w-[16rem]">
                    <option value="0">Unassign</option>
                    <?php foreach ($assignees as $u): ?>
                        <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
            </div>
        <?php endif ?>
    </form>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/leads', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>

<script nonce="<?= e_attr(nonce()) ?>">
(function () {
    var all = document.querySelector('[data-check-all]');
    var boxes = function () { return [].slice.call(document.querySelectorAll('input[name="lead_ids[]"]')); };
    var bar = document.querySelector('[data-bulk-bar]');
    var count = document.querySelector('[data-bulk-count]');
    function refresh() {
        var n = boxes().filter(function (b) { return b.checked; }).length;
        if (count) count.textContent = n;
        if (bar) bar.hidden = n === 0;
    }
    if (all) all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); refresh(); });
    boxes().forEach(function (b) { b.addEventListener('change', refresh); });
})();
</script>
<?php $this->stop(); ?>
