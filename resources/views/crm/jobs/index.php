<?php
/**
 * @var \App\Support\Page $page @var \App\Support\ListQuery $query
 * @var array<string,string> $countries @var array<string,string> $employers @var bool $canCreate
 */
$this->layout('layouts.app', ['title' => 'Jobs', 'currentPath' => '/jobs']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$statusColor = ['draft' => 'slate', 'open' => 'green', 'paused' => 'amber', 'interview' => 'blue', 'filled' => 'indigo', 'closed' => 'slate', 'cancelled' => 'red'];
?>
<?= component('page-header', [
    'title' => 'Jobs',
    'subtitle' => number_format($page->total) . ' total',
    'actions' => $canCreate ? '<a href="/jobs/create" class="btn btn-primary btn-sm">New job</a>' : '',
]) ?>

<form method="get" action="/jobs" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Title or JOB-…">
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
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach (array_keys($statusColor) as $s): ?>
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
        <?php if ($hasFilters): ?><a href="/jobs" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No jobs match these filters' : 'No jobs yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Post the first vacancy for an employer.',
        'action' => $hasFilters ? '<a href="/jobs" class="btn btn-secondary">Clear filters</a>' : ($canCreate ? '<a href="/jobs/create" class="btn btn-primary">New job</a>' : ''),
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
                    return '<th><a class="hover:text-slate-800" href="/jobs?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('title', 'Title');
                ?>
                <th>Job #</th>
                <th>Employer</th>
                <?= $col('country', 'Country') ?>
                <th>Vacancies</th>
                <th>Salary</th>
                <?= $col('status', 'Status') ?>
                <?= $col('deadline', 'Deadline') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $j): /** @var \App\Models\Job $j */ ?>
                <tr>
                    <td><a href="/jobs/<?= e_attr($j->publicId) ?>" class="font-medium text-slate-900"><?= e($j->title) ?></a></td>
                    <td class="font-mono text-xs"><?= e($j->jobNumber) ?></td>
                    <td><a href="/employers/<?= e_attr($j->employerPublicId) ?>" class="text-slate-600"><?= e($j->employerName) ?></a></td>
                    <td><?= e($countries[$j->country] ?? $j->country) ?></td>
                    <td><?= (int) $j->vacancies ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= e($j->salaryLabel()) ?></td>
                    <td>
                        <?= component('badge', ['label' => $j->statusLabel(), 'color' => $statusColor[$j->status] ?? 'slate']) ?>
                        <?php if ($j->isPublic): ?><?= component('badge', ['label' => 'Public', 'color' => 'emerald']) ?><?php endif ?>
                    </td>
                    <td class="whitespace-nowrap text-slate-500"><?= e($j->deadline ?? '—') ?></td>
                    <td class="text-right"><a href="/jobs/<?= e_attr($j->publicId) ?>" class="btn btn-ghost btn-sm">View</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/jobs', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
