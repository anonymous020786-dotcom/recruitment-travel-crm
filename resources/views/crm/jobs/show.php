<?php
/**
 * @var \App\Models\Job $job @var list<\App\Models\JobRequirement> $requirements @var list<array{id:int,label:string}> $benefits
 * @var array<string,string> $countries @var bool $canEdit @var bool $canDelete @var bool $canStatus @var bool $canPublish
 * @var list<string> $nextStatuses
 */
$this->layout('layouts.app', ['title' => $job->title, 'currentPath' => '/jobs']);
$this->start('content');

$statusColor = ['draft' => 'slate', 'open' => 'green', 'paused' => 'amber', 'interview' => 'blue', 'filled' => 'indigo', 'closed' => 'slate', 'cancelled' => 'red'];
$terminal = in_array($job->status, ['closed', 'cancelled'], true);
$base = '/jobs/' . e_attr($job->publicId);

$actions = '';
if ($canEdit && !$terminal) {
    $actions .= '<a href="' . $base . '/edit" class="btn btn-primary btn-sm">Edit</a> ';
}
if ($canDelete && in_array($job->status, ['draft', 'closed', 'cancelled'], true)) {
    $actions .= '<form method="post" action="' . $base . '" class="inline" data-confirm="Delete this job?">'
        . csrf_field() . '<input type="hidden" name="_method" value="DELETE"><button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>';
}
?>
<?= component('page-header', [
    'title' => $job->title,
    'subtitle' => $job->jobNumber . ' · ' . $job->employerName,
    'breadcrumbs' => [['label' => 'Jobs', 'href' => '/jobs'], ['label' => $job->jobNumber]],
    'actions' => $actions,
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $job->statusLabel(), 'color' => $statusColor[$job->status] ?? 'slate', 'dot' => true]) ?>
    <?php if ($job->isPublic): ?><?= component('badge', ['label' => 'Public', 'color' => 'emerald']) ?><?php endif ?>
    <?php if ($job->isDeadlinePassed() && !$terminal): ?><?= component('badge', ['label' => 'Deadline passed', 'color' => 'red', 'dot' => true]) ?><?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Details', 'body' => (function () use ($job, $countries) {
            $prov = ['none' => 'Not provided', 'provided' => 'Provided', 'allowance' => 'Allowance'];
            $rows = [
                'Employer' => '<a href="/employers/' . e_attr($job->employerPublicId) . '" class="text-brand-600 hover:underline">' . e($job->employerName) . '</a>',
                'Location' => e(trim(($job->city ? $job->city . ', ' : '') . ($countries[$job->country] ?? $job->country))),
                'Vacancies' => (string) (int) $job->vacancies,
                'Salary' => e($job->salaryLabel()),
                'Experience' => e($job->experienceRequired ?? '—'),
                'Qualification' => e($job->qualification ?? '—'),
                'Age' => e(($job->ageMin || $job->ageMax) ? (($job->ageMin ?? '—') . '–' . ($job->ageMax ?? '—')) : '—'),
                'Gender' => e(ucfirst($job->genderRequirement)),
                'Accommodation' => e($prov[$job->accommodation] ?? $job->accommodation),
                'Food' => e($prov[$job->food] ?? $job->food),
                'Transport' => e($prov[$job->transport] ?? $job->transport),
                'Working hours' => e($job->workingHours ?? '—'),
                'Overtime' => e($job->overtime ?? '—'),
                'Contract' => e($job->contractDurationMonths ? $job->contractDurationMonths . ' months' : '—'),
                'Interview' => e($job->interviewType ? ucfirst(str_replace('_', ' ', $job->interviewType)) : '—'),
                'Deadline' => e($job->deadline ?? '—'),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }
            $html .= '</dl>';
            if ($job->descriptionHtml) {
                // Sanitised at write time (HtmlSanitizer::fromPlainText) — paragraphs of escaped text only.
                $html .= '<div class="mt-4 space-y-2 border-t border-slate-100 pt-3 text-sm text-slate-700">' . $job->descriptionHtml . '</div>';
            }

            return $html;
        })()]) ?>

        <div id="requirements">
            <?= component('card', ['title' => 'Requirements', 'body' => (function () use ($job, $requirements, $canEdit, $terminal, $base) {
                $html = '';
                if ($canEdit && !$terminal) {
                    $html .= '<form method="post" action="' . $base . '/requirements" class="mb-4 grid gap-2 sm:grid-cols-4" data-once>'
                        . csrf_field()
                        . '<input type="text" name="label" required maxlength="120" placeholder="e.g. Forklift licence" aria-label="Requirement" class="form-input sm:col-span-2">'
                        . '<input type="number" name="weight" min="1" max="10" value="1" aria-label="Weight (1-10)" title="Weight (1-10)" class="form-input">'
                        . '<label class="flex items-center gap-1.5 text-xs text-slate-600"><input type="hidden" name="is_mandatory" value="0"><input type="checkbox" name="is_mandatory" value="1" checked> Mandatory</label>'
                        . '<div><button class="btn btn-secondary btn-sm">Add requirement</button></div></form>';
                }
                if ($requirements === []) {
                    return $html . '<p class="text-sm text-slate-500">No requirements listed.</p>';
                }
                $html .= '<ul class="divide-y divide-slate-100">';
                foreach ($requirements as $r) {
                    $html .= '<li class="flex items-center justify-between gap-2 py-2 text-sm"><span>' . e($r->label) . ' '
                        . component('badge', ['label' => $r->isMandatory ? 'Mandatory' : 'Preferred', 'color' => $r->isMandatory ? 'indigo' : 'slate'])
                        . ' <span class="text-xs text-slate-400">weight ' . (int) $r->weight . ($r->skillId ? ' · in skills catalogue' : '') . '</span></span>';
                    if ($canEdit && !$terminal) {
                        $html .= '<form method="post" action="' . $base . '/requirements/' . $r->id . '">'
                            . csrf_field() . '<input type="hidden" name="_method" value="DELETE"><button class="btn btn-ghost btn-sm text-red-600">Remove</button></form>';
                    }
                    $html .= '</li>';
                }

                return $html . '</ul>';
            })()]) ?>
        </div>

        <div id="benefits">
            <?= component('card', ['title' => 'Benefits', 'body' => (function () use ($benefits, $canEdit, $terminal, $base) {
                $html = '';
                if ($canEdit && !$terminal) {
                    $html .= '<form method="post" action="' . $base . '/benefits" class="mb-4 flex gap-2" data-once>'
                        . csrf_field()
                        . '<input type="text" name="label" required maxlength="120" placeholder="e.g. Annual air ticket" aria-label="Benefit" class="form-input">'
                        . '<button class="btn btn-secondary btn-sm">Add</button></form>';
                }
                if ($benefits === []) {
                    return $html . '<p class="text-sm text-slate-500">No benefits listed.</p>';
                }
                $html .= '<ul class="flex flex-wrap gap-2">';
                foreach ($benefits as $b) {
                    $html .= '<li class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700"><span>' . e($b['label']) . '</span>';
                    if ($canEdit && !$terminal) {
                        $html .= '<form method="post" action="' . $base . '/benefits/' . (int) $b['id'] . '" class="inline">'
                            . csrf_field() . '<input type="hidden" name="_method" value="DELETE">'
                            . '<button class="text-slate-400 hover:text-red-600" aria-label="Remove ' . e($b['label']) . '">&times;</button></form>';
                    }
                    $html .= '</li>';
                }

                return $html . '</ul>';
            })()]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Lifecycle', 'body' => (function () use ($job, $canStatus, $nextStatuses, $base) {
            $html = '<p class="mb-2 text-sm text-slate-600">Status: <span class="font-medium text-slate-900">' . e($job->statusLabel()) . '</span></p>';
            if (!$canStatus || $nextStatuses === []) {
                return $html . ($nextStatuses === [] ? '<p class="text-xs text-slate-500">This status is final.</p>' : '');
            }
            $html .= '<form method="post" action="' . $base . '/status" class="space-y-2" data-once>' . csrf_field()
                . '<select name="status" aria-label="Move to" class="form-select w-full">';
            foreach ($nextStatuses as $s) {
                $html .= '<option value="' . e_attr($s) . '">' . e(ucfirst($s)) . '</option>';
            }
            $html .= '</select><input type="text" name="reason" maxlength="255" placeholder="Reason (required to close/cancel)" aria-label="Reason" class="form-input w-full">'
                . '<button class="btn btn-secondary btn-sm">Move</button></form>';

            return $html;
        })()]) ?>

        <?= component('card', ['title' => 'Public listing', 'body' => (function () use ($job, $canPublish, $base) {
            $html = '<p class="mb-2 text-sm text-slate-600">' . ($job->isPublic ? 'Shown on the public site.' : 'Not shown on the public site.') . '</p>'
                . '<p class="mb-2 text-xs text-slate-400">Slug: <span class="font-mono">' . e($job->slug) . '</span></p>';
            if ($canPublish) {
                $on = !$job->isPublic;
                $html .= '<form method="post" action="' . $base . '/publish">' . csrf_field()
                    . '<input type="hidden" name="public" value="' . ($on ? '1' : '0') . '">'
                    . '<button class="btn btn-secondary btn-sm"' . ($on && $job->status !== 'open' ? ' disabled title="Only an open job can be published"' : '') . '>'
                    . ($on ? 'Publish' : 'Unpublish') . '</button></form>';
            }

            return $html;
        })()]) ?>
    </div>
</div>
<?php $this->stop(); ?>
