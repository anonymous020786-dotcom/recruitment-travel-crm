<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query @var list<array{key:string,label:string}> $stages */
$this->layout('layouts.app', ['title' => 'Candidates', 'currentPath' => '/candidates']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
?>
<?= component('page-header', [
    'title' => 'Candidates',
    'subtitle' => number_format($page->total) . ' total',
]) ?>

<form method="get" action="/candidates" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="lg:col-span-2">
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>"
               placeholder="Name, phone, email or CAND-…">
    </div>
    <div>
        <label class="form-label" for="stage">Stage</label>
        <select class="form-select" id="stage" name="stage">
            <option value="">Any</option>
            <?php foreach ($stages as $s): ?>
                <option value="<?= e_attr($s['key']) ?>" <?= $query->filter('stage') === $s['key'] ? 'selected' : '' ?>>
                    <?= e($s['label']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/candidates" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No candidates match these filters' : 'No candidates yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Candidates are created by converting a lead.',
        'action' => $hasFilters ? '<a href="/candidates" class="btn btn-secondary">Clear filters</a>' : '',
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
                    return '<th><a class="hover:text-slate-800" href="/candidates?' . e_attr(http_build_query($q)) . '">'
                        . e($label) . $arrow . '</a></th>';
                };
                echo $col('name', 'Name');
                ?>
                <th>Candidate #</th>
                <th>Phone</th>
                <?= $col('stage', 'Stage') ?>
                <th>Counselor</th>
                <?= $col('created_at', 'Created') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $c): /** @var \App\Models\Candidate $c */ ?>
                <tr>
                    <td><a href="/candidates/<?= e_attr($c->publicId) ?>" class="font-medium text-slate-900"><?= e($c->fullName) ?></a></td>
                    <td class="font-mono text-xs"><?= e($c->candidateNumber) ?></td>
                    <td class="whitespace-nowrap"><?= $c->primaryPhone ? '<a href="tel:' . e_attr($c->primaryPhone) . '" class="text-slate-600">' . e($c->primaryPhone) . '</a>' : '—' ?></td>
                    <td><?= component('badge', ['label' => $c->stageLabel(), 'color' => 'indigo']) ?></td>
                    <td class="text-slate-600"><?= e($c->counselorName ?? '—') ?></td>
                    <td class="whitespace-nowrap text-slate-500"><?= e(substr($c->createdAt, 0, 10)) ?></td>
                    <td class="text-right"><a href="/candidates/<?= e_attr($c->publicId) ?>" class="btn btn-ghost btn-sm">View</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/candidates', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
