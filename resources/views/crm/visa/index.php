<?php
/**
 * @var \App\Support\Page $page @var \App\Support\ListQuery $query
 * @var list<string> $statuses @var array<string,string> $countries
 */
$this->layout('layouts.app', ['title' => 'Visa', 'currentPath' => '/visa']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
$color = ['not_started' => 'slate', 'documents_pending' => 'amber', 'submitted' => 'blue', 'under_processing' => 'indigo', 'approved' => 'green', 'rejected' => 'red', 'expired' => 'red', 'cancelled' => 'slate'];
$expiryColor = ['expired' => 'red', 'expiring' => 'amber', 'valid' => 'green'];
?>
<?= component('page-header', ['title' => 'Visa applications', 'subtitle' => number_format($page->total) . ' total']) ?>

<form method="get" action="/visa" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Candidate, CAN-…, visa or ref no.">
    </div>
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e_attr($s) ?>" <?= $query->filter('status') === $s ? 'selected' : '' ?>><?= e(\App\Models\VisaApplication::statusLabel($s)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="country">Country</label>
        <select class="form-select" id="country" name="country">
            <option value="">Any</option>
            <?php foreach ($countries as $code => $name): ?>
                <option value="<?= e_attr($code) ?>" <?= strtoupper((string) $query->filter('country')) === $code ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="expiry">Expiry</label>
        <select class="form-select" id="expiry" name="expiry">
            <?php foreach (['' => 'Any', 'expiring' => 'Expiring in 30 days', 'expired' => 'Expired'] as $k => $v): ?>
                <option value="<?= e_attr($k) ?>" <?= (string) $query->filter('expiry') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/visa" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $hasFilters ? 'No visa applications match these filters' : 'No visa applications yet',
        'message' => $hasFilters ? 'Try widening your search.' : 'Start a visa from a candidate’s profile once their medical is complete.',
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
                    return '<th><a class="hover:text-slate-800" href="/visa?' . e_attr(http_build_query($q)) . '">' . e($label) . $arrow . '</a></th>';
                };
                echo $col('candidate', 'Candidate');
                echo $col('country', 'Country');
                ?>
                <th>Type</th>
                <th>Application</th>
                <?= $col('status', 'Status') ?>
                <?= $col('expiry', 'Expires') ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $v): /** @var \App\Models\VisaApplication $v */ $exp = $v->expiryState(); ?>
                <tr>
                    <td><a href="/candidates/<?= e_attr($v->candidatePublicId) ?>" class="font-medium text-slate-900"><?= e($v->candidateName) ?></a>
                        <span class="font-mono text-xs text-slate-400"><?= e($v->candidateNumber) ?></span></td>
                    <td><?= e($countries[$v->country] ?? $v->country) ?></td>
                    <td class="text-slate-600"><?= e($v->visaType ?? '—') ?></td>
                    <td class="font-mono text-xs"><?= e($v->applicationNumber ?? '—') ?></td>
                    <td><?= component('badge', ['label' => $v->label(), 'color' => $color[$v->status] ?? 'slate']) ?></td>
                    <td class="whitespace-nowrap"><?= e($v->expiryDate ?? '—') ?>
                        <?php if ($exp !== null && $exp !== 'valid'): ?><?= component('badge', ['label' => $exp === 'expired' ? 'Expired' : 'Soon', 'color' => $expiryColor[$exp]]) ?><?php endif ?></td>
                    <td class="text-right"><a href="/visa/<?= e_attr($v->publicId) ?>" class="btn btn-ghost btn-sm">View</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('pagination', [
        'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
        'baseUrl' => '/visa', 'query' => $query->toQueryArray(),
    ]) ?>
<?php endif ?>
<?php $this->stop(); ?>
