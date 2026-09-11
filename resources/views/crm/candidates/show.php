<?php
/**
 * @var \App\Models\Candidate $candidate
 * @var list<array{id:int,name:string}> $counselors @var bool $canEdit
 */
$this->layout('layouts.app', ['title' => $candidate->fullName, 'currentPath' => '/candidates']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => $candidate->fullName,
    'subtitle' => $candidate->candidateNumber,
    'breadcrumbs' => [['label' => 'Candidates', 'href' => '/candidates'], ['label' => $candidate->candidateNumber]],
    'actions' => !empty($canEdit)
        ? '<a href="/candidates/' . e_attr($candidate->publicId) . '/edit" class="btn btn-primary btn-sm">Edit</a>'
        : '',
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $candidate->stageLabel(), 'color' => 'indigo', 'dot' => true]) ?>
    <?php if (!$candidate->isActive): ?>
        <?= component('badge', ['label' => 'Inactive', 'color' => 'slate']) ?>
    <?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <?= component('card', ['title' => 'Profile', 'body' => (function () use ($candidate) {
            $rows = [
                'Phone' => $candidate->primaryPhone ? '<a href="tel:' . e_attr($candidate->primaryPhone) . '">' . e($candidate->primaryPhone) . '</a>' : '—',
                'Alternate phone' => $candidate->alternatePhone ? e($candidate->alternatePhone) : '—',
                'Email' => $candidate->email ? '<a href="mailto:' . e_attr($candidate->email) . '">' . e($candidate->email) . '</a>' : '—',
                'Gender' => $candidate->gender ? e(ucfirst($candidate->gender)) : '—',
                'Date of birth' => e($candidate->dateOfBirth ?? '—'),
                'Location' => e(trim(($candidate->city ?? '') . ' ' . ($candidate->state ?? '')) ?: '—'),
                'Nationality' => e($candidate->nationality ?? '—'),
                'Current country' => e($candidate->currentCountry ?? '—'),
                'Marital status' => $candidate->maritalStatus ? e(ucfirst($candidate->maritalStatus)) : '—',
                'Highest qualification' => e($candidate->highestQualification ?? '—'),
                'Experience' => $candidate->totalExperienceYears !== null ? e((string) $candidate->totalExperienceYears) . ' yrs' : '—',
                'Created' => e(substr($candidate->createdAt, 0, 16)),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }
            return $html . '</dl>';
        })()]) ?>

        <div class="mt-4">
            <?= component('card', [
                'title' => 'Education, experience & more',
                'body' => component('empty-state', [
                    'title' => 'Coming next',
                    'message' => 'Education, experience, skills, preferences, passport details, documents, job applications and interview scheduling are built out in follow-up steps.',
                ]),
            ]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Counselor', 'body' => (function () use ($candidate, $counselors, $canEdit) {
            $html = '<p class="text-sm text-slate-600 mb-2">Currently: <span class="font-medium text-slate-900">'
                . e($candidate->counselorName ?? 'Unassigned') . '</span></p>';
            if (!empty($canEdit)) {
                $opts = '<option value="0">Unassigned</option>';
                foreach ($counselors as $u) {
                    $sel = (int) $u['id'] === $candidate->assignedCounselor ? ' selected' : '';
                    $opts .= '<option value="' . (int) $u['id'] . '"' . $sel . '>' . e($u['name']) . '</option>';
                }
                $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/counselor" class="flex gap-2">'
                    . csrf_field()
                    . '<input type="hidden" name="record_version" value="' . (int) $candidate->recordVersion . '">'
                    . '<select name="assigned_counselor" aria-label="Counselor" class="form-select">' . $opts . '</select>'
                    . '<button class="btn btn-secondary btn-sm">Save</button></form>';
            }
            return $html;
        })()]) ?>

        <?= component('card', ['title' => 'Origin', 'body' =>
            $candidate->originLeadPublicId
                ? '<p class="text-sm text-slate-600">Converted from lead</p>'
                    . '<a href="/leads/' . e_attr($candidate->originLeadPublicId) . '" class="font-medium text-brand-600 hover:underline">' . e((string) $candidate->originLeadNumber) . '</a>'
                : '<p class="text-sm text-slate-500">No originating lead on record.</p>',
        ]) ?>
    </div>
</div>
<?php $this->stop(); ?>
