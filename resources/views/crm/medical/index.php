<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query */
$this->layout('layouts.app', ['title' => 'Medical', 'currentPath' => '/medical']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['pending' => 'slate', 'scheduled' => 'blue', 'completed' => 'amber', 'fit' => 'green', 'unfit' => 'red', 'retest' => 'amber'];
$expiryColor = ['expired' => 'red', 'expiring' => 'amber', 'valid' => 'green'];
?>
<?= component('page-header', ['title' => 'Medical', 'subtitle' => number_format($page->total) . ' total']) ?>

<form method="get" action="/medical" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Candidate, CAN-… or centre">
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
        <label class="form-label" for="expiry">Certificate</label>
        <select class="form-select" id="expiry" name="expiry">
            <?php foreach (['' => 'Any', 'expiring' => 'Expiring in 30 days', 'expired' => 'Expired'] as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= (string) $query->filter('expiry') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/medical" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No medical records match these filters' : 'No medical records yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Book a medical from a candidate’s profile.',
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
                    return '<th><a class="hover:text-slate-800" href="/medical?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('candidate', 'Candidate');
                ?>
                <th>Centre</th>
                <th>Application</th>
                <?= $col('appointment', 'Appointment') ?>
                <?= $col('status', 'Status') ?>
                <?= $col('expires', 'Expires') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $m): /** @var \App\Models\MedicalRecord $m */ $exp = $m->expiryState(); ?>
                <tr>
                    <td><a href="/candidates/<?= e_attr($m->candidatePublicId) ?>#medical" class="font-medium text-slate-900"><?= e($m->candidateName) ?></a>
                        <span class="font-mono text-xs text-slate-400"><?= e($m->candidateNumber) ?></span></td>
                    <td class="text-slate-600"><?= e($m->medicalCenter ?? '—') ?></td>
                    <td class="font-mono text-xs"><?= e($m->applicationNumber ?? '—') ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?= e($m->appointmentDate ?? '—') ?></td>
                    <td><?= component('badge', ['label' => $m->statusLabel(), 'color' => $color[$m->status] ?? 'slate']) ?></td>
                    <td class="whitespace-nowrap"><?= e($m->expiresAt ?? '—') ?>
                        <?php if ($exp !== null && $exp !== 'valid'): ?><?= component('badge', ['label' => $exp === 'expired' ? 'Expired' : 'Soon', 'color' => $expiryColor[$exp]]) ?><?php endif ?></td>
                    <td class="text-right"><a href="/candidates/<?= e_attr($m->candidatePublicId) ?>#medical" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/medical', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
