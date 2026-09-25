<?php
/**
 * Interviews card on an application.
 * @var \App\Models\Application $app @var list<\App\Models\Interview> $interviews @var bool $canSchedule
 */
$statusColor = ['scheduled' => 'blue', 'confirmed' => 'indigo', 'completed' => 'amber', 'selected' => 'green', 'rejected' => 'red', 'rescheduled' => 'slate', 'no_show' => 'red'];

/** Shared slot fields; $i pre-fills a reschedule. */
$slotFields = static function (?\App\Models\Interview $i) use ($app): string {
    $types = '';
    foreach (\App\Models\Interview::TYPES as $k => $v) {
        $types .= '<option value="' . e_attr($k) . '"' . ($i !== null && $i->type === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }

    return '<div class="grid gap-2 sm:grid-cols-2">'
        . '<select name="type" aria-label="Interview type" class="form-select">' . $types . '</select>'
        . '<input type="date" name="scheduled_date" required min="' . e_attr(gmdate('Y-m-d')) . '" value="' . e_attr($i?->scheduledDate ?? '') . '" aria-label="Date" class="form-input">'
        . '<input type="time" name="scheduled_time" value="' . e_attr($i?->scheduledTime ?? '') . '" aria-label="Time" class="form-input">'
        . '<input type="text" name="interviewer" maxlength="160" value="' . e_attr($i?->interviewer ?? '') . '" placeholder="Interviewer" aria-label="Interviewer" class="form-input">'
        . '<input type="text" name="location" maxlength="200" value="' . e_attr($i?->location ?? '') . '" placeholder="Location (in person / client visit)" aria-label="Location" class="form-input">'
        . '<input type="url" name="meeting_link" maxlength="255" value="' . e_attr($i?->meetingLink ?? '') . '" placeholder="Meeting link (video)" aria-label="Meeting link" class="form-input">'
        . '</div>';
};

$html = '';

if ($canSchedule) {
    $html .= '<details class="mb-4 rounded border border-slate-200 p-3"><summary class="cursor-pointer text-sm font-medium text-brand-600">Schedule an interview</summary>'
        . '<form method="post" action="/applications/' . e_attr($app->publicId) . '/interviews" class="mt-3 space-y-2" data-once>' . csrf_field()
        . $slotFields(null)
        . '<textarea name="notes" rows="2" maxlength="2000" placeholder="Notes (optional)" aria-label="Notes" class="form-input w-full"></textarea>'
        . '<button class="btn btn-primary btn-sm">Schedule</button></form></details>';
}

if ($interviews === []) {
    $html .= '<p class="text-sm text-slate-500">No interviews yet.' . ($app->status === 'applied' || $app->status === 'documents_submitted' ? ' Shortlist the candidate to schedule one.' : '') . '</p>';
} else {
    $html .= '<ol class="space-y-4">';
    foreach ($interviews as $i) {
        /** @var \App\Models\Interview $i */
        $base = '/interviews/' . e_attr($i->publicId);
        $html .= '<li class="rounded border border-slate-100 p-3 text-sm"><div class="flex flex-wrap items-center justify-between gap-2">'
            . '<p class="font-medium text-slate-900">Round ' . (int) $i->roundNo . ' · ' . e($i->typeLabel()) . ' · ' . e($i->whenLabel()) . '</p>'
            . component('badge', ['label' => $i->statusLabel(), 'color' => $statusColor[$i->status] ?? 'slate', 'dot' => true]) . '</div>';

        $bits = array_filter([
            $i->interviewer ? 'Interviewer: ' . e($i->interviewer) : null,
            $i->location ? 'Location: ' . e($i->location) : null,
            $i->meetingLink ? 'Link: <a href="' . e_attr($i->meetingLink) . '" target="_blank" rel="noopener noreferrer" class="text-brand-600">join</a>' : null,
        ]);
        if ($bits !== []) {
            $html .= '<p class="mt-1 text-slate-600">' . implode(' · ', $bits) . '</p>';
        }
        if ($i->notes) {
            $html .= '<p class="mt-1 whitespace-pre-line text-xs text-slate-500">' . e($i->notes) . '</p>';
        }
        if ($i->feedback) {
            $html .= '<p class="mt-1 text-slate-700"><span class="text-slate-500">Feedback:</span> ' . e($i->feedback) . '</p>';
        }

        if ($i->isOpen() && !$app->isTerminal()) {
            $html .= '<div class="mt-3 space-y-2 border-t border-slate-100 pt-3">';
            if ($i->status === 'scheduled' && can('edit', $i)) {
                $html .= '<form method="post" action="' . $base . '/confirm" class="inline" data-once>' . csrf_field() . '<button class="btn btn-secondary btn-sm">Mark confirmed</button></form> ';
            }
            if (can('recordOutcome', $i)) {
                $html .= '<details><summary class="cursor-pointer text-xs font-medium text-brand-600">Record outcome</summary>'
                    . '<form method="post" action="' . $base . '/outcome" class="mt-2 space-y-2" data-once>' . csrf_field()
                    . '<select name="outcome" aria-label="Outcome" class="form-select w-full">'
                    . '<option value="selected">Selected</option><option value="rejected">Rejected</option><option value="hold">On hold</option><option value="no_show">No-show</option></select>'
                    . '<textarea name="feedback" rows="2" maxlength="4000" placeholder="Feedback (required when rejecting)" aria-label="Feedback" class="form-input w-full"></textarea>'
                    . '<button class="btn btn-primary btn-sm">Save outcome</button></form></details>';
            }
            if (can('edit', $i)) {
                $html .= '<details><summary class="cursor-pointer text-xs font-medium text-slate-600">Reschedule</summary>'
                    . '<form method="post" action="' . $base . '/reschedule" class="mt-2 space-y-2" data-once>' . csrf_field()
                    . $slotFields($i)
                    . '<input type="text" name="reason" required maxlength="200" placeholder="Reason for rescheduling" aria-label="Reason" class="form-input w-full">'
                    . '<button class="btn btn-secondary btn-sm">Reschedule</button></form></details>';
            }
            $html .= '</div>';
        }
        $html .= '</li>';
    }
    $html .= '</ol>';
}

echo component('card', ['title' => 'Interviews', 'body' => $html]);
