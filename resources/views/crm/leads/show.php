<?php
/**
 * @var \App\Models\Lead $lead
 * @var list<array> $timeline @var list<array> $followups @var list $assignees @var list<string> $nextStatuses
 */
$this->layout('layouts.app', ['title' => $lead->name, 'currentPath' => '/leads']);
$this->start('content');

$wa = 'https://wa.me/' . preg_replace('/\D/', '', $lead->phone);
$statusLabels = [];
foreach (($nextStatuses ?? []) as $s) {
    $statusLabels[$s] = ucwords(str_replace('_', ' ', $s));
}
?>
<?= component('page-header', [
    'title' => $lead->name,
    'subtitle' => $lead->leadNumber,
    'breadcrumbs' => [['label' => 'Leads', 'href' => '/leads'], ['label' => $lead->leadNumber]],
    'actions' => implode(' ', array_filter([
        '<a href="tel:' . e_attr($lead->phone) . '" class="btn btn-secondary btn-sm">Call</a>',
        '<a href="' . e_attr($wa) . '" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">WhatsApp</a>',
        ($lead->isEditable() && can('update', $lead)) ? '<a href="/leads/' . e_attr($lead->publicId) . '/edit" class="btn btn-primary btn-sm">Edit</a>' : '',
    ])),
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $lead->statusLabel, 'color' => $lead->statusColor(), 'dot' => true]) ?>
    <?= component('badge', ['label' => ucfirst($lead->priority) . ' priority', 'color' => $lead->priorityColor()]) ?>
    <?php if ($lead->isConverted()): ?>
        <?= component('badge', ['label' => 'Converted', 'color' => 'emerald', 'dot' => true]) ?>
    <?php endif ?>
</div>

<?php if ($lead->isEditable() && can('changeStatus', $lead) && $statusLabels !== []): ?>
    <div class="card card-body mb-4">
        <p class="form-label mb-2">Move to</p>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($statusLabels as $key => $label): ?>
                <?php $needsReason = in_array($key, ['lost', 'not_interested'], true); ?>
                <form method="post" action="/leads/<?= e_attr($lead->publicId) ?>/status" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="status" value="<?= e_attr($key) ?>">
                    <input type="hidden" name="record_version" value="<?= (int) $lead->recordVersion ?>">
                    <?php if ($needsReason): ?>
                        <input type="text" name="reason" required placeholder="Reason…"
                               class="form-input inline-block w-40 py-1 text-xs align-middle">
                    <?php endif ?>
                    <button type="submit" class="btn btn-secondary btn-sm"><?= e($label) ?></button>
                </form>
            <?php endforeach ?>
        </div>
    </div>
<?php endif ?>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Details', 'body' => (function () use ($lead) {
            $rows = [
                'Phone' => '<a href="tel:' . e_attr($lead->phone) . '">' . e($lead->phone) . '</a>',
                'Alternate phone' => $lead->alternatePhone ? e($lead->alternatePhone) : '—',
                'Email' => $lead->email ? '<a href="mailto:' . e_attr($lead->email) . '">' . e($lead->email) . '</a>' : '—',
                'Location' => e(trim(($lead->city ?? '') . ' ' . ($lead->state ?? '')) ?: '—'),
                'Source' => e($lead->sourceName ?? '—'),
                'Campaign' => e($lead->campaign ?? '—'),
                'Interested country' => e($lead->interestedCountry ?? '—'),
                'Interested job' => e($lead->interestedJob ?? '—'),
                'Experience' => $lead->experienceYears !== null ? e((string) $lead->experienceYears) . ' yrs' : '—',
                'Qualification' => e($lead->qualification ?? '—'),
                'Salary expectation' => $lead->salaryExpectation ? e(($lead->salaryCurrency ?? '') . ' ' . $lead->salaryExpectation) : '—',
                'Created' => e(substr($lead->createdAt, 0, 16)),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }
            $html .= '</dl>';
            if ($lead->notes) {
                $html .= '<div class="mt-3 border-t border-slate-100 pt-3"><dt class="text-slate-500 text-sm">Notes</dt>'
                    . '<dd class="mt-1 whitespace-pre-wrap text-sm text-slate-900">' . e($lead->notes) . '</dd></div>';
            }
            return $html;
        })()]) ?>

        <div id="timeline">
            <?= component('card', ['title' => 'Timeline', 'body' => (function () use ($timeline, $lead) {
                $html = '';
                if (can('addNote', $lead)) {
                    $html .= '<form method="post" action="/leads/' . e_attr($lead->publicId) . '/notes" class="mb-4 flex gap-2">'
                        . csrf_field()
                        . '<input type="text" name="body" required maxlength="5000" placeholder="Add a note…" class="form-input">'
                        . '<button class="btn btn-secondary">Add</button></form>';
                }
                if ($timeline === []) {
                    $html .= '<p class="text-sm text-slate-500">No activity yet.</p>';
                } else {
                    $html .= '<ol class="space-y-3">';
                    foreach ($timeline as $item) {
                        $icon = $item['type'] === 'note' ? '📝' : '•';
                        $html .= '<li class="flex gap-3 text-sm">'
                            . '<span class="mt-0.5 text-slate-400">' . $icon . '</span>'
                            . '<div><p class="text-slate-800">' . e($item['text']) . '</p>'
                            . '<p class="text-xs text-slate-400">'
                            . ($item['actor'] ? e($item['actor']) . ' · ' : '')
                            . e(substr($item['at'], 0, 16)) . '</p></div></li>';
                    }
                    $html .= '</ol>';
                }
                return $html;
            })()]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Assignment', 'body' => (function () use ($lead, $assignees) {
            $html = '<p class="text-sm text-slate-600 mb-2">Currently: <span class="font-medium text-slate-900">'
                . e($lead->assignedToName ?? 'Unassigned') . '</span></p>';
            if (can('assign', $lead)) {
                $opts = '<option value="0">Unassigned</option>';
                foreach ($assignees as $u) {
                    $sel = (int) $u['id'] === $lead->assignedTo ? ' selected' : '';
                    $opts .= '<option value="' . (int) $u['id'] . '"' . $sel . '>' . e($u['name']) . '</option>';
                }
                $html .= '<form method="post" action="/leads/' . e_attr($lead->publicId) . '/assign" class="flex gap-2">'
                    . csrf_field()
                    . '<input type="hidden" name="record_version" value="' . (int) $lead->recordVersion . '">'
                    . '<select name="assigned_to" class="form-select">' . $opts . '</select>'
                    . '<button class="btn btn-secondary btn-sm">Save</button></form>';
            }
            return $html;
        })()]) ?>

        <?php if ($lead->isEditable() && can('delete', $lead)): ?>
            <?= component('card', ['title' => 'Danger zone', 'body' =>
                '<form method="post" action="/leads/' . e_attr($lead->publicId) . '" data-confirm="Delete this lead? It can be restored by an admin.">'
                . csrf_field()
                . '<input type="hidden" name="_method" value="DELETE">'
                . '<input type="hidden" name="record_version" value="' . (int) $lead->recordVersion . '">'
                . '<button class="btn btn-danger btn-sm">Delete lead</button></form>',
            ]) ?>
        <?php endif ?>
    </div>
</div>
<?php $this->stop(); ?>
