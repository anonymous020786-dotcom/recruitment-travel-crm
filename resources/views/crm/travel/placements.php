<?php
/**
 * @var \App\Support\Page $page @var \App\Support\ListQuery $query @var array<string,string> $employers
 */
$this->layout('layouts.app', ['title' => 'Placements', 'currentPath' => '/travel']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['active' => 'green', 'completed' => 'indigo', 'terminated' => 'red', 'absconded' => 'red'];
?>
<?= component('page-header', [
    'title' => 'Placements',
    'subtitle' => number_format($page->total) . ' total',
    'breadcrumbs' => [['label' => 'Travel', 'href' => '/travel'], ['label' => 'Placements']],
]) ?>

<form method="get" action="/placements" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Candidate, CAN-… or employer">
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
        <label class="form-label" for="employer">Employer</label>
        <select class="form-select" id="employer" name="employer">
            <option value="">Any</option>
            <?php foreach ($employers as $publicId => $name): ?>
                <option value="<?= e_attr((string) $publicId) ?>" <?= $query->filter('employer') === (string) $publicId ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/placements" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No placements match these filters' : 'No placements yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'A placement is recorded once a departed candidate’s arrival is confirmed.',
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
                    return '<th><a class="hover:text-slate-800" href="/placements?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('candidate', 'Candidate');
                ?>
                <?= $col('employer', 'Employer') ?>
                <th>Job</th>
                <?= $col('placed_on', 'Placed') ?>
                <th>Salary</th>
                <?= $col('status', 'Status') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $p): /** @var \App\Models\Placement $p */ ?>
                <tr>
                    <td><a href="/candidates/<?= e_attr($p->candidatePublicId) ?>" class="font-medium text-slate-900"><?= e($p->candidateName) ?></a>
                        <span class="font-mono text-xs text-slate-400"><?= e($p->candidateNumber) ?></span></td>
                    <td><a href="/employers/<?= e_attr($p->employerPublicId) ?>" class="text-brand-600 hover:underline"><?= e($p->employerName) ?></a></td>
                    <td class="text-slate-600"><?= e($p->jobTitle) ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= e($p->placedOn) ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= $p->monthlySalary !== null ? e(($p->currency ?? '') . ' ' . number_format((float) $p->monthlySalary, 2)) : '—' ?></td>
                    <td><?= component('badge', ['label' => $p->statusLabel(), 'color' => $color[$p->status] ?? 'slate']) ?></td>
                    <td class="text-right"><a href="/applications/<?= e_attr($p->applicationPublicId) ?>#travel" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/placements', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
