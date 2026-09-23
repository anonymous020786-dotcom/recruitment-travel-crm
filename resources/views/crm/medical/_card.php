<?php
/**
 * Medical card on a candidate.
 * @var \App\Models\Candidate $candidate @var list<\App\Models\MedicalRecord> $medical
 * @var list<\App\Models\Application> $candidateApplications @var bool $canBook
 */
$color = ['pending' => 'slate', 'scheduled' => 'blue', 'completed' => 'amber', 'fit' => 'green', 'unfit' => 'red', 'retest' => 'amber'];
$expiryColor = ['expired' => 'red', 'expiring' => 'amber', 'valid' => 'green'];

$html = '';

if ($canBook) {
    $apps = '<option value="">General medical (no application)</option>';
    foreach ($candidateApplications as $a) {
        if (!$a->isTerminal()) {
            $apps .= '<option value="' . e_attr($a->publicId) . '">' . e($a->applicationNumber . ' · ' . $a->jobTitle) . '</option>';
        }
    }
    $html .= '<details class="mb-4 rounded border border-slate-200 p-3"><summary class="cursor-pointer text-sm font-medium text-brand-600">Book a medical</summary>'
        . '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/medical" class="mt-3 space-y-2" data-once>' . csrf_field()
        . '<select name="application" aria-label="Application" class="form-select w-full">' . $apps . '</select>'
        . '<div class="grid gap-2 sm:grid-cols-2">'
        . '<input type="text" name="medical_center" maxlength="180" placeholder="Medical centre" aria-label="Medical centre" class="form-input">'
        . '<input type="date" name="appointment_date" min="' . e_attr(gmdate('Y-m-d')) . '" aria-label="Appointment date" class="form-input"></div>'
        . '<textarea name="notes" rows="2" maxlength="2000" placeholder="Notes (optional)" aria-label="Notes" class="form-input w-full"></textarea>'
        . '<button class="btn btn-primary btn-sm">Book</button></form></details>';
}

if ($medical === []) {
    $html .= '<p class="text-sm text-slate-500">No medical records yet.</p>';
}

$html .= $medical === [] ? '' : '<ul class="space-y-4">';
foreach ($medical as $m) {
    /** @var \App\Models\MedicalRecord $m */
    $base = '/medical/' . e_attr($m->publicId);
    $exp = $m->expiryState();
    $html .= '<li class="rounded border border-slate-100 p-3 text-sm"><div class="flex flex-wrap items-center justify-between gap-2">'
        . '<p class="font-medium text-slate-900">' . e($m->medicalCenter ?? 'Medical centre not set')
        . ($m->applicationNumber ? ' <span class="font-mono text-xs text-slate-400">' . e($m->applicationNumber) . '</span>' : '') . '</p>'
        . '<span class="flex gap-1">' . component('badge', ['label' => $m->statusLabel(), 'color' => $color[$m->status] ?? 'slate', 'dot' => true])
        . ($exp !== null ? component('badge', ['label' => $exp === 'expired' ? 'Expired' : ($exp === 'expiring' ? 'Expiring soon' : 'Valid'), 'color' => $expiryColor[$exp]]) : '') . '</span></div>';

    $rows = array_filter([
        'Appointment' => $m->appointmentDate, 'Attended' => $m->medicalDate, 'Report' => $m->reportDate, 'Certificate expires' => $m->expiresAt,
    ]);
    if ($rows !== []) {
        $html .= '<p class="mt-1 text-slate-600">';
        $parts = [];
        foreach ($rows as $k => $v) {
            $parts[] = e($k) . ': ' . e((string) $v);
        }
        $html .= implode(' · ', $parts) . '</p>';
    }
    if ($m->notes) {
        $html .= '<p class="mt-1 whitespace-pre-line text-xs text-slate-500">' . e($m->notes) . '</p>';
    }

    if ($m->isOpen() && can('edit', $m)) {
        $html .= '<div class="mt-3 space-y-2 border-t border-slate-100 pt-3">';
        if (in_array($m->status, ['pending', 'scheduled'], true)) {
            $html .= '<details><summary class="cursor-pointer text-xs font-medium text-slate-600">' . ($m->status === 'pending' ? 'Set appointment' : 'Change appointment') . '</summary>'
                . '<form method="post" action="' . $base . '/reschedule" class="mt-2 space-y-2" data-once>' . csrf_field()
                . '<div class="grid gap-2 sm:grid-cols-2"><input type="text" name="medical_center" maxlength="180" value="' . e_attr($m->medicalCenter ?? '') . '" placeholder="Medical centre" aria-label="Medical centre" class="form-input">'
                . '<input type="date" name="appointment_date" min="' . e_attr(gmdate('Y-m-d')) . '" value="' . e_attr($m->appointmentDate ?? '') . '" aria-label="Appointment date" class="form-input"></div>'
                . '<button class="btn btn-secondary btn-sm">Save</button></form></details>';
            $html .= '<form method="post" action="' . $base . '/attended" class="flex items-center gap-2" data-once>' . csrf_field()
                . '<input type="date" name="medical_date" required max="' . e_attr(gmdate('Y-m-d')) . '" value="' . e_attr(gmdate('Y-m-d')) . '" aria-label="Date attended" class="form-input">'
                . '<button class="btn btn-secondary btn-sm">Mark attended</button></form>';
        }
        if ($m->status !== 'pending') {
            $html .= '<details><summary class="cursor-pointer text-xs font-medium text-brand-600">Record result</summary>'
            . '<form method="post" action="' . $base . '/result" class="mt-2 space-y-2" data-once>' . csrf_field()
            . '<select name="result" aria-label="Result" class="form-select w-full"><option value="fit">Fit</option><option value="unfit">Unfit</option><option value="retest">Retest needed</option></select>'
            . '<div class="grid gap-2 sm:grid-cols-2">'
            . '<label class="text-xs text-slate-500">Report date<input type="date" name="report_date" max="' . e_attr(gmdate('Y-m-d')) . '" value="' . e_attr(gmdate('Y-m-d')) . '" class="form-input mt-1 w-full"></label>'
            . '<label class="text-xs text-slate-500">Certificate expires (fit)<input type="date" name="expires_at" class="form-input mt-1 w-full"></label></div>'
            . '<textarea name="notes" rows="2" maxlength="2000" placeholder="Notes (optional)" aria-label="Notes" class="form-input w-full"></textarea>'
            . '<p class="text-xs text-slate-400">If no expiry is given a fit certificate is treated as valid for ' . (int) \App\Services\MedicalService::DEFAULT_VALIDITY_DAYS . ' days.</p>'
            . '<button class="btn btn-primary btn-sm">Save result</button></form></details>';
        }
        if (in_array($m->status, ['pending', 'scheduled'], true) && can('delete', $m)) {
            $html .= '<form method="post" action="' . $base . '" class="inline" data-confirm="Delete this medical?">' . csrf_field()
                . '<input type="hidden" name="_method" value="DELETE"><button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>';
        }
        $html .= '</div>';
    }
    $html .= '</li>';
}
$html .= $medical === [] ? '' : '</ul>';

echo component('card', ['title' => 'Medical', 'body' => $html]);
