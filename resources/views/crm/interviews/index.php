<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query */
$this->layout('layouts.app', ['title' => 'Interviews', 'currentPath' => '/interviews']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['scheduled' => 'blue', 'confirmed' => 'indigo', 'completed' => 'amber', 'selected' => 'green', 'rejected' => 'red', 'rescheduled' => 'slate', 'no_show' => 'red'];
$today = gmdate('Y-m-d');
?>
<?= component('page-header', ['title' => 'Interviews', 'subtitle' => number_format($page->total) . ' total']) ?>

<form method="get" action="/interviews" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Candidate or APP-…">
    </div>
    <div>
        <label class="form-label" for="when">When</label>
        <select class="form-select" id="when" name="when">
            <?php foreach (['' => 'Any time', 'today' => 'Today', 'upcoming' => 'Upcoming (open)', 'past' => 'Past'] as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= (string) $query->filter('when') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
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
        <label class="form-label" for="type">Type</label>
        <select class="form-select" id="type" name="type">
            <option value="">Any</option>
            <?php foreach (\App\Models\Interview::TYPES as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= $query->filter('type') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/interviews" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No interviews match these filters' : 'No interviews yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Shortlist a candidate on an application, then schedule the interview from its page.',
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
                    return '<th><a class="hover:text-slate-800" href="/interviews?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('when', 'When');
                echo $col('candidate', 'Candidate');
                ?>
                <th>Job</th>
                <th>Round</th>
                <th>Type</th>
                <?= $col('status', 'Status') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $i): /** @var \App\Models\Interview $i */ ?>
                <tr>
                    <td class="whitespace-nowrap <?= $i->isOpen() && $i->scheduledDate < $today ? 'text-red-600' : 'text-slate-700' ?>"><?= e($i->whenLabel()) ?></td>
                    <td><a href="/candidates/<?= e_attr($i->candidatePublicId) ?>" class="font-medium text-slate-900"><?= e($i->candidateName) ?></a></td>
                    <td class="text-slate-600"><?= e($i->jobTitle) ?> <span class="text-xs text-slate-400">· <?= e($i->employerName) ?></span></td>
                    <td><?= (int) $i->roundNo ?></td>
                    <td class="text-slate-600"><?= e($i->typeLabel()) ?></td>
                    <td><?= component('badge', ['label' => $i->statusLabel(), 'color' => $color[$i->status] ?? 'slate']) ?></td>
                    <td class="text-right"><a href="/applications/<?= e_attr($i->applicationPublicId) ?>#interviews" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/interviews', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
