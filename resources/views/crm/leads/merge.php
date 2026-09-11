<?php
/**
 * @var \App\Models\Lead $lead                                  the survivor (kept)
 * @var list<array{summary:array<string,mixed>,full:?\App\Models\Lead}> $duplicates
 * @var string $preselect                                        public id to open by default
 * @var list<string> $mergeFields
 */
$this->layout('layouts.app', ['title' => 'Merge leads', 'currentPath' => '/leads']);
$this->start('content');

$labels = [
    'name' => 'Name', 'phone' => 'Phone', 'alternate_phone' => 'Alternate phone', 'email' => 'Email',
    'priority' => 'Priority', 'assigned_to' => 'Assignee (id)', 'source_id' => 'Source (id)', 'campaign' => 'Campaign',
    'interested_country' => 'Country', 'interested_job' => 'Job', 'experience_years' => 'Experience',
    'qualification' => 'Qualification', 'salary_expectation' => 'Salary', 'salary_currency' => 'Currency',
    'city' => 'City', 'state' => 'State', 'gender' => 'Gender', 'date_of_birth' => 'Date of birth',
];
$val = static fn ($v): string => ($v === null || $v === '') ? '—' : (string) $v;
?>
<?= component('page-header', [
    'title' => 'Merge into ' . $lead->name,
    'subtitle' => $lead->leadNumber . ' will be kept',
    'breadcrumbs' => [['label' => 'Leads', 'href' => '/leads'], ['label' => $lead->leadNumber, 'href' => '/leads/' . $lead->publicId], ['label' => 'Merge']],
]) ?>

<p class="mb-4 max-w-2xl text-sm text-slate-600">
    Pick a likely-duplicate lead to fold into <strong><?= e($lead->leadNumber) ?></strong>.
    Its notes and follow-ups move across, empty fields here are filled from it, and it is then
    closed and redirects here. Tick a field to take that value <em>from</em> the merged lead instead.
</p>

<?php if ($duplicates === []): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => 'No likely duplicates',
        'message' => 'Nothing matches this lead\'s phone, alternate phone or email within your branches.',
    ])]) ?>
<?php else: ?>
    <div class="space-y-3">
    <?php foreach ($duplicates as $d): $s = $d['summary']; $full = $d['full']; $pid = (string) $s['public_id']; ?>
        <details class="card" <?= $pid === $preselect ? 'open' : '' ?>>
            <summary class="card-body cursor-pointer list-none">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-medium text-slate-900"><?= e((string) $s['name']) ?></span>
                        <span class="text-xs text-slate-400"><?= e((string) $s['lead_number']) ?></span>
                        <span class="ml-2 text-sm text-slate-500"><?= e((string) $s['phone']) ?><?= $s['email'] ? ' · ' . e((string) $s['email']) : '' ?></span>
                    </div>
                    <span class="text-xs text-slate-400">created <?= e(substr((string) $s['created_at'], 0, 10)) ?> · <?= e((string) ($s['status_label'] ?? '')) ?></span>
                </div>
            </summary>
            <div class="border-t border-slate-100 p-4">
                <?php if ($full === null || !$full->isEditable()): ?>
                    <p class="text-sm text-amber-600">This lead can't be merged (converted, already merged, or outside your scope).</p>
                <?php else: ?>
                    <form method="post" action="/leads/<?= e_attr($lead->publicId) ?>/merge" data-once
                          data-confirm="Merge <?= e_attr((string) $s['lead_number']) ?> into <?= e_attr($lead->leadNumber) ?>? This can't be undone from the UI.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="loser" value="<?= e_attr($pid) ?>">
                        <input type="hidden" name="record_version" value="<?= (int) $lead->recordVersion ?>">

                        <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-xs uppercase text-slate-400">
                                <th class="py-1 pr-3">Field</th>
                                <th class="py-1 pr-3">Keep (<?= e($lead->leadNumber) ?>)</th>
                                <th class="py-1 pr-3">Merged lead</th>
                                <th class="py-1">Take theirs</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($mergeFields as $f):
                                $sv = $lead->raw[$f] ?? null;
                                $lv = $full->raw[$f] ?? null;
                                $same = (string) $sv === (string) $lv;
                                $loserEmpty = $lv === null || $lv === '';
                            ?>
                                <tr class="border-t border-slate-50 <?= $same ? 'text-slate-400' : '' ?>">
                                    <td class="py-1.5 pr-3 text-slate-500"><?= e($labels[$f] ?? $f) ?></td>
                                    <td class="py-1.5 pr-3"><?= e($val($sv)) ?></td>
                                    <td class="py-1.5 pr-3"><?= e($val($lv)) ?></td>
                                    <td class="py-1.5">
                                        <?php if (!$same && !$loserEmpty): ?>
                                            <input type="checkbox" name="take[]" value="<?= e_attr($f) ?>"
                                                   <?= ($sv === null || $sv === '') ? 'checked' : '' ?>>
                                        <?php elseif (!$same && $loserEmpty): ?>
                                            <span class="text-xs text-slate-300">n/a</span>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-300">=</span>
                                        <?php endif ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                        </div>

                        <?php if (trim((string) $full->notes) !== '' && trim((string) $full->notes) !== trim((string) $lead->notes)): ?>
                            <p class="mt-2 text-xs text-slate-500">The merged lead's notes will be appended.</p>
                        <?php endif ?>

                        <button type="submit" class="btn btn-primary btn-sm mt-3">Merge this lead in</button>
                    </form>
                <?php endif ?>
            </div>
        </details>
    <?php endforeach ?>
    </div>
<?php endif ?>

<p class="mt-4 text-sm"><a href="/leads/<?= e_attr($lead->publicId) ?>">Back to the lead</a></p>
<?php $this->stop(); ?>
