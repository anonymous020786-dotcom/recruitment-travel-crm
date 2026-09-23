<?php
/**
 * Visa card on a candidate.
 * @var \App\Models\Candidate $candidate @var list<\App\Models\VisaApplication> $visas
 * @var list<\App\Models\Application> $candidateApplications @var array<string,string> $countries @var bool $canCreate
 */
$color = ['not_started' => 'slate', 'documents_pending' => 'amber', 'submitted' => 'blue', 'under_processing' => 'indigo', 'approved' => 'green', 'rejected' => 'red', 'expired' => 'red', 'cancelled' => 'slate'];
$expiryColor = ['expired' => 'red', 'expiring' => 'amber', 'valid' => 'green'];

$html = '';

if ($canCreate) {
    $apps = '<option value="">Stand-alone visa (no application)</option>';
    foreach ($candidateApplications as $a) {
        if (in_array($a->status, ['medical_completed', 'visa_processing'], true)) {
            $apps .= '<option value="' . e_attr($a->publicId) . '">' . e($a->applicationNumber . ' · ' . $a->jobTitle) . '</option>';
        }
    }
    $countryOptions = '';
    foreach ($countries as $code => $name) {
        $countryOptions .= '<option value="' . e_attr($code) . '">' . e($name) . '</option>';
    }
    $html .= '<details class="mb-4 rounded border border-slate-200 p-3"><summary class="cursor-pointer text-sm font-medium text-brand-600">Start a visa application</summary>'
        . '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/visa" class="mt-3 space-y-2" data-once>' . csrf_field()
        . '<select name="application" aria-label="Application" class="form-select w-full">' . $apps . '</select>'
        . '<p class="text-xs text-slate-400">An application must have its medical completed before a visa can be started for it.</p>'
        . '<div class="grid gap-2 sm:grid-cols-2">'
        . '<select name="country" required aria-label="Country" class="form-select">' . $countryOptions . '</select>'
        . '<input type="text" name="visa_type" maxlength="80" placeholder="Visa type (e.g. Employment)" aria-label="Visa type" class="form-input">'
        . '<input type="text" name="reference_number" maxlength="80" placeholder="Reference number" aria-label="Reference number" class="form-input">'
        . '<input type="text" name="sponsor" maxlength="180" placeholder="Sponsor (defaults to the employer)" aria-label="Sponsor" class="form-input"></div>'
        . '<button class="btn btn-primary btn-sm">Start</button></form></details>';
}

if ($visas === []) {
    $html .= '<p class="text-sm text-slate-500">No visa applications yet.</p>';
} else {
    $html .= '<ul class="divide-y divide-slate-100">';
    foreach ($visas as $v) {
        /** @var \App\Models\VisaApplication $v */
        $exp = $v->expiryState();
        $html .= '<li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"><div>'
            . '<a href="/visa/' . e_attr($v->publicId) . '" class="font-medium text-slate-900">' . e(($countries[$v->country] ?? $v->country) . ($v->visaType ? ' · ' . $v->visaType : '')) . '</a>'
            . ($v->applicationNumber ? ' <span class="font-mono text-xs text-slate-400">' . e($v->applicationNumber) . '</span>' : '')
            . ($v->expiryDate ? '<p class="text-xs text-slate-500">Expires ' . e($v->expiryDate) . '</p>' : '') . '</div>'
            . '<span class="flex gap-1">' . component('badge', ['label' => $v->label(), 'color' => $color[$v->status] ?? 'slate', 'dot' => true])
            . ($exp !== null && $exp !== 'valid' ? component('badge', ['label' => $exp === 'expired' ? 'Expired' : 'Expiring soon', 'color' => $expiryColor[$exp]]) : '') . '</span></li>';
    }
    $html .= '</ul>';
}

echo component('card', ['title' => 'Visa', 'body' => $html]);
