<?php
/**
 * @var \App\Models\Candidate $candidate
 * @var list<array{id:int,name:string}> $counselors @var bool $canEdit
 * @var list<\App\Models\CandidateEducation> $education @var list<\App\Models\CandidateExperience> $experience
 * @var bool $canEducation @var bool $canExperience @var array<string,string> $countries
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

        <div id="education" class="mt-4">
            <?= component('card', ['title' => 'Education', 'body' => (function () use ($candidate, $education, $canEducation) {
                $html = '';

                if ($canEducation) {
                    $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/education" class="mb-4 grid gap-2 sm:grid-cols-4" data-once>'
                        . csrf_field()
                        . '<input type="text" name="level" required maxlength="60" placeholder="Level (e.g. Bachelor)" aria-label="Level" class="form-input">'
                        . '<input type="text" name="institution" maxlength="180" placeholder="Institution" aria-label="Institution" class="form-input">'
                        . '<input type="text" name="board_university" maxlength="180" placeholder="Board / University" aria-label="Board or university" class="form-input">'
                        . '<input type="text" name="field_of_study" maxlength="120" placeholder="Field of study" aria-label="Field of study" class="form-input">'
                        . '<input type="number" name="start_year" min="1950" max="2100" placeholder="Start year" aria-label="Start year" class="form-input">'
                        . '<input type="number" name="end_year" min="1950" max="2100" placeholder="End year" aria-label="End year" class="form-input">'
                        . '<input type="text" name="grade" maxlength="40" placeholder="Grade" aria-label="Grade" class="form-input">'
                        . '<div><button class="btn btn-secondary btn-sm">Add education</button></div>'
                        . '</form>';
                }

                if ($education === []) {
                    $html .= '<p class="text-sm text-slate-500">No education records yet.</p>';
                    return $html;
                }

                $html .= '<ul class="divide-y divide-slate-100">';
                foreach ($education as $e) {
                    $meta = implode(' · ', array_filter([
                        $e->boardUniversity, $e->fieldOfStudy,
                        ($e->startYear || $e->endYear) ? (($e->startYear ?? '—') . '–' . ($e->endYear ?? '—')) : null,
                        $e->grade ? 'Grade: ' . $e->grade : null,
                    ]));
                    $html .= '<li class="py-2.5 text-sm">'
                        . '<div class="flex items-start justify-between gap-2">'
                        . '<div><p class="font-medium text-slate-900">' . e($e->level) . ($e->institution ? ' — ' . e($e->institution) : '') . '</p>'
                        . ($meta !== '' ? '<p class="text-xs text-slate-500">' . e($meta) . '</p>' : '')
                        . '</div>';

                    if ($canEducation) {
                        $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/education/' . $e->id . '"'
                            . ' data-confirm="Remove this education record?">'
                            . csrf_field() . '<input type="hidden" name="_method" value="DELETE">'
                            . '<button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>';
                    }
                    $html .= '</div>';

                    if ($canEducation) {
                        $html .= '<details class="mt-1.5"><summary class="cursor-pointer text-xs text-brand-600">Edit</summary>'
                            . '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/education/' . $e->id . '"'
                            . ' class="mt-2 grid gap-2 sm:grid-cols-4">'
                            . csrf_field() . '<input type="hidden" name="_method" value="PUT">'
                            . '<input type="text" name="level" required maxlength="60" value="' . e_attr($e->level) . '" aria-label="Level" class="form-input">'
                            . '<input type="text" name="institution" maxlength="180" value="' . e_attr($e->institution ?? '') . '" aria-label="Institution" class="form-input">'
                            . '<input type="text" name="board_university" maxlength="180" value="' . e_attr($e->boardUniversity ?? '') . '" aria-label="Board or university" class="form-input">'
                            . '<input type="text" name="field_of_study" maxlength="120" value="' . e_attr($e->fieldOfStudy ?? '') . '" aria-label="Field of study" class="form-input">'
                            . '<input type="number" name="start_year" min="1950" max="2100" value="' . e_attr((string) ($e->startYear ?? '')) . '" aria-label="Start year" class="form-input">'
                            . '<input type="number" name="end_year" min="1950" max="2100" value="' . e_attr((string) ($e->endYear ?? '')) . '" aria-label="End year" class="form-input">'
                            . '<input type="text" name="grade" maxlength="40" value="' . e_attr($e->grade ?? '') . '" aria-label="Grade" class="form-input">'
                            . '<div><button class="btn btn-secondary btn-sm">Save</button></div>'
                            . '</form></details>';
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
                return $html;
            })()]) ?>
        </div>

        <div id="experience" class="mt-4">
            <?= component('card', ['title' => 'Experience', 'body' => (function () use ($candidate, $experience, $canExperience, $countries) {
                $html = '';
                $countryOpts = '<option value="">—</option>';
                foreach ($countries as $code => $name) {
                    $countryOpts .= '<option value="' . e_attr($code) . '">' . e($name) . '</option>';
                }

                if ($canExperience) {
                    $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/experience" class="mb-4 grid gap-2 sm:grid-cols-3" data-once>'
                        . csrf_field()
                        . '<input type="text" name="employer_name" required maxlength="180" placeholder="Employer" aria-label="Employer" class="form-input">'
                        . '<input type="text" name="job_title" required maxlength="120" placeholder="Job title" aria-label="Job title" class="form-input">'
                        . '<select name="country" aria-label="Country" class="form-select">' . $countryOpts . '</select>'
                        . '<input type="date" name="start_date" aria-label="Start date" class="form-input">'
                        . '<input type="date" name="end_date" aria-label="End date" class="form-input">'
                        . '<label class="flex items-center gap-1.5 text-xs text-slate-600"><input type="checkbox" name="is_current" value="1"> Current job</label>'
                        . '<textarea name="responsibilities" maxlength="2000" placeholder="Responsibilities (optional)" aria-label="Responsibilities" class="form-textarea sm:col-span-3" rows="2"></textarea>'
                        . '<div><button class="btn btn-secondary btn-sm">Add experience</button></div>'
                        . '</form>';
                }

                if ($experience === []) {
                    $html .= '<p class="text-sm text-slate-500">No experience records yet.</p>';
                    return $html;
                }

                $html .= '<ul class="divide-y divide-slate-100">';
                foreach ($experience as $x) {
                    $meta = implode(' · ', array_filter([$x->country, $x->durationLabel()]));
                    $html .= '<li class="py-2.5 text-sm">'
                        . '<div class="flex items-start justify-between gap-2">'
                        . '<div><p class="font-medium text-slate-900">' . e($x->jobTitle) . ' — ' . e($x->employerName) . '</p>'
                        . '<p class="text-xs text-slate-500">' . e($meta) . '</p>'
                        . ($x->responsibilities ? '<p class="mt-1 text-slate-600">' . e($x->responsibilities) . '</p>' : '')
                        . '</div>';

                    if ($canExperience) {
                        $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/experience/' . $x->id . '"'
                            . ' data-confirm="Remove this experience record?">'
                            . csrf_field() . '<input type="hidden" name="_method" value="DELETE">'
                            . '<button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>';
                    }
                    $html .= '</div>';

                    if ($canExperience) {
                        $curOpts = '<option value="">—</option>';
                        foreach ($countries as $code => $name) {
                            $sel = $code === $x->country ? ' selected' : '';
                            $curOpts .= '<option value="' . e_attr($code) . '"' . $sel . '>' . e($name) . '</option>';
                        }
                        $html .= '<details class="mt-1.5"><summary class="cursor-pointer text-xs text-brand-600">Edit</summary>'
                            . '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/experience/' . $x->id . '"'
                            . ' class="mt-2 grid gap-2 sm:grid-cols-3">'
                            . csrf_field() . '<input type="hidden" name="_method" value="PUT">'
                            . '<input type="text" name="employer_name" required maxlength="180" value="' . e_attr($x->employerName) . '" aria-label="Employer" class="form-input">'
                            . '<input type="text" name="job_title" required maxlength="120" value="' . e_attr($x->jobTitle) . '" aria-label="Job title" class="form-input">'
                            . '<select name="country" aria-label="Country" class="form-select">' . $curOpts . '</select>'
                            . '<input type="date" name="start_date" value="' . e_attr($x->startDate ?? '') . '" aria-label="Start date" class="form-input">'
                            . '<input type="date" name="end_date" value="' . e_attr($x->endDate ?? '') . '" aria-label="End date" class="form-input">'
                            . '<label class="flex items-center gap-1.5 text-xs text-slate-600"><input type="checkbox" name="is_current" value="1"' . ($x->isCurrent ? ' checked' : '') . '> Current job</label>'
                            . '<textarea name="responsibilities" maxlength="2000" aria-label="Responsibilities" class="form-textarea sm:col-span-3" rows="2">' . e($x->responsibilities ?? '') . '</textarea>'
                            . '<div><button class="btn btn-secondary btn-sm">Save</button></div>'
                            . '</form></details>';
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
                return $html;
            })()]) ?>
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
