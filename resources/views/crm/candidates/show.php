<?php
/** @var \App\Models\Candidate $candidate */
$this->layout('layouts.app', ['title' => $candidate->fullName, 'currentPath' => '/candidates']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => $candidate->fullName,
    'subtitle' => $candidate->candidateNumber,
    'breadcrumbs' => [['label' => 'Candidates', 'href' => '/candidates'], ['label' => $candidate->candidateNumber]],
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
                'Counselor' => e($candidate->counselorName ?? 'Unassigned'),
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
                'title' => 'Profile & pipeline',
                'body' => component('empty-state', [
                    'title' => 'Full candidate module coming in a later phase',
                    'message' => 'Education, experience, skills, passport details, documents, job applications and interview scheduling are built out next.',
                ]),
            ]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Origin', 'body' =>
            $candidate->originLeadPublicId
                ? '<p class="text-sm text-slate-600">Converted from lead</p>'
                    . '<a href="/leads/' . e_attr($candidate->originLeadPublicId) . '" class="font-medium text-brand-600 hover:underline">' . e((string) $candidate->originLeadNumber) . '</a>'
                : '<p class="text-sm text-slate-500">No originating lead on record.</p>',
        ]) ?>
    </div>
</div>
<?php $this->stop(); ?>
