<?php
/**
 * @var \App\Models\Lead $lead
 * @var list<array> $timeline @var list<\App\Models\Followup> $followups @var list $assignees
 * @var list<string> $nextStatuses @var bool $canFollowup @var bool $canConvert
 * @var \App\Models\Candidate|null $convertedCandidate
 */
$this->layout('layouts.app', ['title' => $lead->name, 'currentPath' => '/leads']);
$this->start('content');

$canFollowup = $canFollowup ?? false;
$channels = ['call' => 'Call', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email', 'meeting' => 'Meeting', 'other' => 'Other'];
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
        (!empty($canMerge) && $lead->isEditable()) ? '<a href="/leads/' . e_attr($lead->publicId) . '/merge" class="btn btn-secondary btn-sm">Merge</a>' : '',
        ($lead->isEditable() && can('update', $lead)) ? '<a href="/leads/' . e_attr($lead->publicId) . '/edit" class="btn btn-primary btn-sm">Edit</a>' : '',
    ])),
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $lead->statusLabel, 'color' => $lead->statusColor(), 'dot' => true]) ?>
    <?= component('badge', ['label' => ucfirst($lead->priority) . ' priority', 'color' => $lead->priorityColor()]) ?>
    <?php if ($lead->isConverted()): ?>
        <?= component('badge', ['label' => 'Converted', 'color' => 'emerald', 'dot' => true]) ?>
        <?php if ($convertedCandidate !== null): ?>
            <a href="/candidates/<?= e_attr($convertedCandidate->publicId) ?>" class="text-sm text-brand-600 hover:underline">
                View candidate <?= e($convertedCandidate->candidateNumber) ?>
            </a>
        <?php endif ?>
    <?php endif ?>
</div>

<?php if (!empty($canConvert)): ?>
    <div class="card card-body mb-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-slate-600">Ready to move forward? Converting creates a candidate record and closes this lead as won.</p>
        <form method="post" action="/leads/<?= e_attr($lead->publicId) ?>/convert"
              data-confirm="Convert this lead to a candidate? This can't be undone from here.">
            <?= csrf_field() ?>
            <input type="hidden" name="record_version" value="<?= (int) $lead->recordVersion ?>">
            <button type="submit" class="btn btn-primary btn-sm">Convert to candidate</button>
        </form>
    </div>
<?php endif ?>

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

        <div id="followups">
            <?= component('card', ['title' => 'Follow-ups', 'body' => (function () use ($followups, $lead, $assignees, $canFollowup, $channels) {
                $today = gmdate('Y-m-d');
                $html = '';

                if ($canFollowup) {
                    $opts = '';
                    foreach ($assignees as $u) {
                        $sel = (int) $u['id'] === $lead->assignedTo ? ' selected' : '';
                        $opts .= '<option value="' . (int) $u['id'] . '"' . $sel . '>' . e($u['name']) . '</option>';
                    }
                    $chOpts = '';
                    foreach ($channels as $key => $label) {
                        $chOpts .= '<option value="' . e_attr($key) . '">' . e($label) . '</option>';
                    }
                    $html .= '<form method="post" action="/leads/' . e_attr($lead->publicId) . '/followups" class="mb-4 grid gap-2 sm:grid-cols-2" data-once>'
                        . csrf_field()
                        . '<input type="date" name="due_date" required min="' . e_attr($today) . '" value="' . e_attr($today) . '" class="form-input">'
                        . '<input type="time" name="due_time" class="form-input">'
                        . '<select name="channel" class="form-select">' . $chOpts . '</select>'
                        . '<select name="assigned_to" class="form-select">' . $opts . '</select>'
                        . '<input type="text" name="subject" maxlength="200" placeholder="What is it about? (optional)" class="form-input sm:col-span-2">'
                        . '<div class="sm:col-span-2"><button class="btn btn-secondary btn-sm">Schedule follow-up</button></div>'
                        . '</form>';
                }

                if ($followups === []) {
                    $html .= '<p class="text-sm text-slate-500">No follow-ups scheduled.</p>';
                    return $html;
                }

                $html .= '<ul class="divide-y divide-slate-100">';
                foreach ($followups as $f) {
                    $overdue = $f->isOverdue($today);
                    $meta = e($f->channelLabel()) . ' · due ' . e($f->dueLabel())
                        . ($f->assigneeName ? ' · ' . e($f->assigneeName) : '');
                    $badge = match ($f->status) {
                        'completed' => component('badge', ['label' => 'Done', 'color' => 'emerald']),
                        'cancelled' => component('badge', ['label' => 'Cancelled', 'color' => 'slate']),
                        default     => component('badge', ['label' => $overdue ? 'Overdue' : 'Pending', 'color' => $overdue ? 'rose' : 'amber', 'dot' => true]),
                    };
                    $html .= '<li class="py-2.5 text-sm">'
                        . '<div class="flex items-start justify-between gap-2">'
                        . '<div><p class="font-medium text-slate-900">' . e($f->subject ?: $f->channelLabel() . ' follow-up') . '</p>'
                        . '<p class="text-xs text-slate-500">' . $meta . '</p>'
                        . ($f->outcome ? '<p class="mt-1 text-slate-600">' . e($f->outcome) . '</p>' : '')
                        . '</div>' . $badge . '</div>';

                    if ($f->isPending() && $canFollowup) {
                        $html .= '<div class="mt-2 flex flex-wrap items-center gap-2">'
                            . '<form method="post" action="/followups/' . (int) $f->id . '/complete" class="flex flex-1 gap-2" data-once>'
                            . csrf_field()
                            . '<input type="text" name="outcome" required maxlength="255" placeholder="Outcome…" class="form-input">'
                            . '<label class="flex items-center gap-1 whitespace-nowrap text-xs text-slate-500"><input type="checkbox" name="log_as_note" value="1"> note</label>'
                            . '<button class="btn btn-primary btn-sm">Done</button></form>'
                            . '<form method="post" action="/followups/' . (int) $f->id . '/cancel" data-confirm="Cancel this follow-up?">'
                            . csrf_field()
                            . '<button class="btn btn-ghost btn-sm text-red-600">Cancel</button></form>'
                            . '</div>';
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
                return $html;
            })()]) ?>
        </div>

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
