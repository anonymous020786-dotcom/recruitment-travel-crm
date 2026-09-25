<?php
/**
 * @var list<array<string,mixed>> $rows @var int $total @var bool $capped @var int $page @var int $perPage
 * @var array{module:string,q:string,from:string,to:string,record_type:string,record_id:string} $filters
 * @var array<string,list<string>> $errors @var list<string> $modules @var array<string,mixed> $submitted
 */
$this->layout('layouts.app', ['title' => 'Audit log', 'currentPath' => '/admin/audit']);
$this->start('content');

$err = static fn (string $k): ?string => $errors[$k][0] ?? null;
$val = static fn (string $k): string => $errors === [] ? $filters[$k] : (string) ($submitted[$k] ?? '');
$query = array_filter($filters, static fn (string $v): bool => $v !== '');
$label = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
?>
<?= component('page-header', [
    'title' => 'Audit log',
    'subtitle' => $capped ? 'More than ' . number_format($total) . ' entries — narrow the dates or filters' : number_format($total) . ' ' . ($total === 1 ? 'entry' : 'entries'),
]) ?>

<?php if ($errors !== []): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => 'Some filters were not valid, so the default view is shown: ' . implode(' ', array_map(static fn (array $m): string => $m[0], $errors))]) ?></div><?php endif ?>

<form method="get" action="/admin/audit" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($val('q')) ?>" maxlength="80" placeholder="Person, action or note">
    </div>
    <div>
        <label class="form-label" for="module">Module</label>
        <select class="form-select" id="module" name="module">
            <option value="">Any</option>
            <?php foreach ($modules as $m): ?><option value="<?= e_attr($m) ?>" <?= $val('module') === $m ? 'selected' : '' ?>><?= e($label($m)) ?></option><?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="from">From</label>
        <input class="form-input" type="date" id="from" name="from" value="<?= e_attr($val('from')) ?>" <?= $err('from') ? 'aria-invalid="true"' : '' ?>>
    </div>
    <div>
        <label class="form-label" for="to">To</label>
        <input class="form-input" type="date" id="to" name="to" value="<?= e_attr($val('to')) ?>" <?= $err('to') ? 'aria-invalid="true"' : '' ?>>
    </div>
    <div>
        <label class="form-label" for="record_type">Record type</label>
        <input class="form-input" id="record_type" name="record_type" value="<?= e_attr($val('record_type')) ?>" maxlength="40" placeholder="e.g. invoice">
    </div>
    <div>
        <label class="form-label" for="record_id">Record id</label>
        <input class="form-input" id="record_id" name="record_id" value="<?= e_attr($val('record_id')) ?>" maxlength="20" inputmode="numeric">
    </div>
    <div class="flex items-end gap-2 lg:col-span-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="/admin/audit" class="btn btn-ghost">Reset (last 30 days)</a>
    </div>
</form>

<?php if ($rows === []): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No entries match', 'message' => 'Try a wider date range or fewer filters.'])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Audit log">
            <thead><tr><th>When (UTC)</th><th>Who</th><th>What</th><th>Record</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="whitespace-nowrap text-sm text-slate-600"><?= e(substr((string) $r['created_at'], 0, 16)) ?></td>
                    <td class="text-sm text-slate-800"><?= $r['user_id'] === null ? '<span class="text-slate-500">System</span>' : e((string) ($r['user_name'] ?? 'Deleted user')) ?></td>
                    <td class="text-sm"><span class="font-medium text-slate-900"><?= e($label((string) $r['action'])) ?></span><span class="block text-xs text-slate-500"><?= e($label((string) $r['module'])) ?></span></td>
                    <td class="text-sm text-slate-700">
                        <?= e((string) $r['record_type']) ?><?php if ($r['record_id'] !== null): ?> <a class="text-brand-600 hover:underline" href="/admin/audit?<?= e_attr(http_build_query(['record_type' => $r['record_type'], 'record_id' => $r['record_id'], 'from' => '2000-01-01', 'to' => gmdate('Y-m-d')])) ?>" title="Everything that happened to this record">#<?= e((string) $r['record_id']) ?></a><?php endif ?>
                    </td>
                    <td class="text-sm">
                        <?php if ($r['context'] || $r['old_pretty'] || $r['new_pretty'] || $r['ip']): ?>
                            <details>
                                <summary class="cursor-pointer text-xs font-medium text-brand-600">View<span class="sr-only"> details of entry <?= (int) $r['id'] ?></span></summary>
                                <dl class="mt-2 space-y-2 text-xs text-slate-700">
                                    <?php if ($r['context']): ?><div><dt class="font-medium text-slate-500">Note</dt><dd class="whitespace-pre-line"><?= e((string) $r['context']) ?></dd></div><?php endif ?>
                                    <?php if ($r['old_pretty']): ?><div><dt class="font-medium text-slate-500">Before</dt><dd><pre class="max-w-xl overflow-x-auto rounded bg-slate-50 p-2"><?= e((string) $r['old_pretty']) ?></pre></dd></div><?php endif ?>
                                    <?php if ($r['new_pretty']): ?><div><dt class="font-medium text-slate-500">After</dt><dd><pre class="max-w-xl overflow-x-auto rounded bg-slate-50 p-2"><?= e((string) $r['new_pretty']) ?></pre></dd></div><?php endif ?>
                                    <?php if ($r['ip']): ?><div><dt class="font-medium text-slate-500">From</dt><dd><?= e((string) $r['ip']) ?><?php if ($r['user_agent']): ?> · <?= e((string) $r['user_agent']) ?><?php endif ?></dd></div><?php endif ?>
                                </dl>
                            </details>
                        <?php else: ?><span class="text-slate-400">—</span><?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/admin/audit', 'query' => $query]) ?>
<?php endif ?>
<?php $this->stop(); ?>
