<?php
/**
 * @var \App\Support\Page $page @var \App\Support\ListQuery $query @var array<string,int> $counts
 */
$this->layout('layouts.app', ['title' => 'Travel', 'currentPath' => '/travel']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$stageLabel = ['visa_approved' => 'Visa approved', 'ticket_pending' => 'Ticket pending', 'ticket_booked' => 'Ticket booked', 'departed' => 'Departed'];
$stageColor = ['visa_approved' => 'indigo', 'ticket_pending' => 'amber', 'ticket_booked' => 'blue', 'departed' => 'green'];
$flightColor = ['planned' => 'slate', 'booked' => 'blue', 'issued' => 'green', 'changed' => 'amber', 'flown' => 'indigo'];
?>
<?= component('page-header', [
    'title' => 'Travel',
    'subtitle' => number_format($page->total) . ' on the travel desk',
    'actions' => '<a href="/placements" class="btn btn-secondary btn-sm">Placements</a>',
]) ?>

<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <?php foreach ($stageLabel as $stage => $label): ?>
        <a href="/travel?<?= e_attr(http_build_query(['status' => $stage])) ?>" class="card card-body block hover:border-brand-300">
            <p class="text-xs uppercase tracking-wide text-slate-500"><?= e($label) ?></p>
            <p class="mt-1 text-2xl font-semibold text-slate-900"><?= (int) ($counts[$stage] ?? 0) ?></p>
        </a>
    <?php endforeach ?>
</div>

<form method="get" action="/travel" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Candidate, CAN-…, APP-… or PNR">
    </div>
    <div>
        <label class="form-label" for="status">Stage</label>
        <select class="form-select" id="status" name="status">
            <option value="">All stages</option>
            <?php foreach ($stageLabel as $s => $label): ?>
                <option value="<?= e_attr($s) ?>" <?= $query->filter('status') === $s ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/travel" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'Nothing matches these filters' : 'Nobody is at the travel stage yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Candidates appear here once their visa is approved.',
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
                    return '<th><a class="hover:text-slate-800" href="/travel?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('candidate', 'Candidate');
                ?>
                <th>Job</th>
                <?= $col('status', 'Stage') ?>
                <th>Flight</th>
                <?= $col('departure', 'Departs') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $r): ?>
                <tr>
                    <td><a href="/candidates/<?= e_attr((string) $r['candidate_public_id']) ?>" class="font-medium text-slate-900"><?= e((string) $r['candidate_name']) ?></a>
                        <span class="font-mono text-xs text-slate-400"><?= e((string) $r['candidate_number']) ?></span></td>
                    <td class="text-slate-600"><?= e((string) $r['job_title']) ?><br><span class="text-xs text-slate-400"><?= e((string) $r['employer_name']) ?></span></td>
                    <td><?= component('badge', ['label' => $stageLabel[$r['status']] ?? (string) $r['status'], 'color' => $stageColor[$r['status']] ?? 'slate']) ?></td>
                    <td class="text-slate-600">
                        <?php if ($r['flight_public_id'] !== null): ?>
                            <?= e(($r['departure_airport'] ?? '—') . ' → ' . ($r['arrival_airport'] ?? '—')) ?>
                            <?= component('badge', ['label' => ucfirst((string) $r['flight_status']), 'color' => $flightColor[$r['flight_status']] ?? 'slate']) ?>
                            <?php if ($r['pnr']): ?><span class="font-mono text-xs text-slate-400"><?= e((string) $r['pnr']) ?></span><?php endif ?>
                        <?php else: ?>—<?php endif ?>
                    </td>
                    <td class="whitespace-nowrap text-slate-600"><?= e($r['departed_at'] ? substr((string) $r['departed_at'], 0, 16) : ($r['departure_at'] ? substr((string) $r['departure_at'], 0, 16) : '—')) ?></td>
                    <td class="text-right"><a href="/applications/<?= e_attr((string) $r['public_id']) ?>#travel" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/travel', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
