<?php
/**
 * @var \App\Support\Page $page @var \App\Support\ListQuery $query
 * @var list<string> $statuses @var array<string,string> $employers
 */
$this->layout('layouts.app', ['title' => 'Applications', 'currentPath' => '/applications']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = static fn (string $s): string => match (true) {
    in_array($s, ['rejected', 'cancelled'], true) => 'red',
    $s === 'placed' => 'green',
    in_array($s, ['applied', 'documents_submitted'], true) => 'slate',
    default => 'indigo',
};
?>
<?= component('page-header', ['title' => 'Applications', 'subtitle' => number_format($page->total) . ' total']) ?>

<form method="get" action="/applications" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Candidate or APP-…">
    </div>
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e_attr($s) ?>" <?= $query->filter('status') === $s ? 'selected' : '' ?>><?= e(\App\Models\Application::statusLabel($s)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="employer">Employer</label>
        <select class="form-select" id="employer" name="employer">
            <option value="">Any</option>
            <?php foreach ($employers as $pid => $name): ?>
                <option value="<?= e_attr($pid) ?>" <?= $query->filter('employer') === $pid ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/applications" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No applications match these filters' : 'No applications yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Applications are created from a job’s “Find matches” page or a candidate’s “Suggested jobs”.',
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
                    return '<th><a class="hover:text-slate-800" href="/applications?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('candidate', 'Candidate');
                ?>
                <th>Application #</th>
                <th>Job</th>
                <th>Employer</th>
                <?= $col('score', 'Match') ?>
                <?= $col('status', 'Status') ?>
                <?= $col('applied_at', 'Applied') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $a): /** @var \App\Models\Application $a */ ?>
                <tr>
                    <td><a href="/candidates/<?= e_attr($a->candidatePublicId) ?>" class="font-medium text-slate-900"><?= e($a->candidateName) ?></a></td>
                    <td class="font-mono text-xs"><?= e($a->applicationNumber) ?></td>
                    <td><a href="/jobs/<?= e_attr($a->jobPublicId) ?>" class="text-slate-700"><?= e($a->jobTitle) ?></a></td>
                    <td class="text-slate-600"><?= e($a->employerName) ?></td>
                    <td><?= $a->matchScore !== null ? number_format($a->matchScore, 1) : '—' ?></td>
                    <td><?= component('badge', ['label' => $a->label(), 'color' => $color($a->status)]) ?></td>
                    <td class="whitespace-nowrap text-slate-500"><?= e(substr($a->appliedAt, 0, 10)) ?></td>
                    <td class="text-right"><a href="/applications/<?= e_attr($a->publicId) ?>" class="btn btn-ghost btn-sm">View</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/applications', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
