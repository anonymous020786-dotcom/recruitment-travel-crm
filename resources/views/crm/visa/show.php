<?php
/**
 * @var \App\Models\VisaApplication $visa
 * @var list<array{from:?string,to:string,is_override:bool,reason:?string,by:?string,at:string}> $history
 * @var array<string,string> $countries
 * @var bool $canEdit @var bool $canStatus @var bool $canOverride @var bool $canDelete
 * @var list<string> $nextStatuses @var list<string> $allStatuses
 */
$this->layout('layouts.app', ['title' => 'Visa · ' . $visa->candidateName, 'currentPath' => '/visa']);
$this->start('content');

$color = ['not_started' => 'slate', 'documents_pending' => 'amber', 'submitted' => 'blue', 'under_processing' => 'indigo', 'approved' => 'green', 'rejected' => 'red', 'expired' => 'red', 'cancelled' => 'slate'];
$label = static fn (string $s): string => \App\Models\VisaApplication::statusLabel($s);
$base = '/visa/' . e_attr($visa->publicId);
$countryName = $countries[$visa->country] ?? $visa->country;
$exp = $visa->expiryState();
?>
<?= component('page-header', [
    'title' => $visa->candidateName . ' — ' . $countryName . ' visa',
    'subtitle' => $visa->candidateNumber . ($visa->applicationNumber ? ' · ' . $visa->applicationNumber : ''),
    'breadcrumbs' => [['label' => 'Visa', 'href' => '/visa'], ['label' => $visa->candidateName]],
    'actions' => $canDelete
        ? '<form method="post" action="' . $base . '" class="inline" data-confirm="Delete this visa application?">' . csrf_field() . '<input type="hidden" name="_method" value="DELETE"><button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>'
        : '',
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $visa->label(), 'color' => $color[$visa->status] ?? 'slate', 'dot' => true]) ?>
    <?php if ($exp === 'expired'): ?><?= component('badge', ['label' => 'Expired', 'color' => 'red', 'dot' => true]) ?>
    <?php elseif ($exp === 'expiring'): ?><?= component('badge', ['label' => 'Expiring soon', 'color' => 'amber', 'dot' => true]) ?><?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Details', 'body' => (function () use ($visa, $countryName) {
            $rows = [
                'Candidate' => '<a href="/candidates/' . e_attr($visa->candidatePublicId) . '#visa" class="text-brand-600 hover:underline">' . e($visa->candidateName) . '</a>',
                'Application' => $visa->applicationPublicId
                    ? '<a href="/applications/' . e_attr($visa->applicationPublicId) . '" class="text-brand-600 hover:underline">' . e($visa->applicationNumber . ' · ' . $visa->jobTitle) . '</a>'
                    : '—',
                'Country' => e($countryName),
                'Visa type' => e($visa->visaType ?? '—'),
                'Visa number' => e($visa->visaNumber ?? '—'),
                'Reference' => e($visa->referenceNumber ?? '—'),
                'Sponsor' => e($visa->sponsor ?? '—'),
                'Submitted' => e($visa->submissionDate ?? '—'),
                'Approved' => e($visa->approvalDate ?? '—'),
                'Expires' => e($visa->expiryDate ?? '—'),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }
            $html .= '</dl>';
            if ($visa->notes) {
                $html .= '<p class="mt-3 whitespace-pre-line border-t border-slate-100 pt-3 text-sm text-slate-600">' . e($visa->notes) . '</p>';
            }

            return $html;
        })()]) ?>

        <?php if ($canEdit): ?>
            <?= component('card', ['title' => 'Edit details', 'body' => (function () use ($visa, $countries, $base) {
                $opts = '';
                foreach ($countries as $code => $name) {
                    $opts .= '<option value="' . e_attr($code) . '"' . ($code === $visa->country ? ' selected' : '') . '>' . e($name) . '</option>';
                }
                $in = static fn (string $name, ?string $val, string $ph, int $max): string => '<input type="text" name="' . $name . '" maxlength="' . $max . '" value="' . e_attr($val ?? '') . '" placeholder="' . e_attr($ph) . '" aria-label="' . e_attr($ph) . '" class="form-input">';

                return '<form method="post" action="' . $base . '" class="space-y-2" data-once>' . csrf_field()
                    . '<input type="hidden" name="_method" value="PUT"><input type="hidden" name="record_version" value="' . (int) $visa->recordVersion . '">'
                    . '<div class="grid gap-2 sm:grid-cols-2"><select name="country" aria-label="Country" class="form-select">' . $opts . '</select>'
                    . $in('visa_type', $visa->visaType, 'Visa type', 80) . $in('visa_number', $visa->visaNumber, 'Visa number', 80)
                    . $in('reference_number', $visa->referenceNumber, 'Reference number', 80) . $in('sponsor', $visa->sponsor, 'Sponsor', 180) . '</div>'
                    . '<textarea name="notes" rows="2" maxlength="2000" placeholder="Notes" aria-label="Notes" class="form-input w-full">' . e($visa->notes ?? '') . '</textarea>'
                    . '<button class="btn btn-secondary btn-sm">Save details</button></form>';
            })()]) ?>
        <?php endif ?>

        <div id="history">
            <?= component('card', ['title' => 'Status history', 'body' => (function () use ($history, $label) {
                $html = '<ol class="space-y-3 text-sm">';
                foreach ($history as $h) {
                    $html .= '<li class="flex gap-3"><span class="mt-0.5 text-slate-400" aria-hidden="true">•</span><div>'
                        . '<p class="text-slate-800">' . ($h['from'] !== null ? e($label($h['from'])) . ' → ' : '') . '<strong>' . e($label($h['to'])) . '</strong>'
                        . ($h['is_override'] ? ' ' . component('badge', ['label' => 'Override', 'color' => 'amber', 'dot' => true]) : '') . '</p>'
                        . ($h['reason'] ? '<p class="text-slate-600">' . e($h['reason']) . '</p>' : '')
                        . '<p class="text-xs text-slate-400">' . ($h['by'] ? e($h['by']) . ' · ' : '') . e(substr($h['at'], 0, 16)) . '</p></div></li>';
                }

                return $html . '</ol>';
            })()]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Move visa application', 'body' => (function () use ($visa, $canStatus, $canOverride, $nextStatuses, $allStatuses, $label, $base) {
            if (!$canStatus) {
                return '<p class="text-sm text-slate-500">You do not have permission to change the status.</p>';
            }
            $fields = static fn (): string => '<label class="block text-xs text-slate-500">Submission date<input type="date" name="submission_date" max="' . e_attr(gmdate('Y-m-d')) . '" class="form-input mt-1 w-full"></label>'
                . '<label class="block text-xs text-slate-500">Approval date<input type="date" name="approval_date" max="' . e_attr(gmdate('Y-m-d')) . '" class="form-input mt-1 w-full"></label>'
                . '<label class="block text-xs text-slate-500">Visa expiry (required to approve)<input type="date" name="expiry_date" value="' . e_attr($visa->expiryDate ?? '') . '" class="form-input mt-1 w-full"></label>'
                . '<input type="text" name="visa_number" maxlength="80" value="' . e_attr($visa->visaNumber ?? '') . '" placeholder="Visa number" aria-label="Visa number" class="form-input w-full">';

            if ($nextStatuses === []) {
                $html = '<p class="text-xs text-slate-500">No further moves are allowed from ' . e($visa->label()) . '.</p>';
            } else {
                $html = '<form method="post" action="' . $base . '/status" class="space-y-2" data-once>' . csrf_field()
                    . '<input type="hidden" name="record_version" value="' . (int) $visa->recordVersion . '"><select name="status" aria-label="Move to" class="form-select w-full">';
                foreach ($nextStatuses as $s) {
                    $html .= '<option value="' . e_attr($s) . '">' . e($label($s)) . '</option>';
                }
                $html .= '</select>' . $fields()
                    . '<input type="text" name="reason" maxlength="255" placeholder="Reason (required to reject/cancel)" aria-label="Reason" class="form-input w-full">'
                    . '<button class="btn btn-secondary btn-sm">Move</button></form>';
            }

            if ($canOverride) {
                $html .= '<details class="mt-4 border-t border-slate-100 pt-3"><summary class="cursor-pointer text-xs font-medium text-amber-700">Override (audited)</summary>'
                    . '<form method="post" action="' . $base . '/status" class="mt-2 space-y-2" data-once>' . csrf_field()
                    . '<input type="hidden" name="record_version" value="' . (int) $visa->recordVersion . '"><input type="hidden" name="override" value="1">'
                    . '<select name="status" aria-label="Override to" class="form-select w-full">';
                foreach ($allStatuses as $s) {
                    $html .= '<option value="' . e_attr($s) . '">' . e($label($s)) . '</option>';
                }
                $html .= '</select><input type="text" name="reason" required maxlength="255" placeholder="Why is this override needed? (required)" aria-label="Override reason" class="form-input w-full">'
                    . '<button class="btn btn-ghost btn-sm text-amber-700">Override</button></form></details>';
            }

            return $html;
        })()]) ?>
    </div>
</div>
<?php $this->stop(); ?>
