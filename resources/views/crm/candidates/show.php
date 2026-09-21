<?php
/**
 * @var \App\Models\Candidate $candidate
 * @var list<array{id:int,name:string}> $counselors @var bool $canEdit
 * @var list<\App\Models\CandidateEducation> $education @var list<\App\Models\CandidateExperience> $experience
 * @var bool $canEducation @var bool $canExperience @var array<string,string> $countries
 * @var list<\App\Models\CandidateSkill> $skills @var bool $canSkills
 * @var \App\Models\CandidatePreferences|null $preferences @var bool $canPreferences
 * @var list<\App\Models\Passport> $passports @var bool $canPassport
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

        <div id="skills" class="mt-4">
            <?= component('card', ['title' => 'Skills', 'body' => (function () use ($candidate, $skills, $canSkills) {
                $html = '';
                $profOpts = '';
                foreach (['basic' => 'Basic', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced', 'expert' => 'Expert'] as $val => $label) {
                    $profOpts .= '<option value="' . $val . '">' . $label . '</option>';
                }

                if ($canSkills) {
                    $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/skills" class="mb-4 grid gap-2 sm:grid-cols-4" data-once>'
                        . csrf_field()
                        . '<input type="text" name="skill_name" required maxlength="80" placeholder="Skill (e.g. MS Excel)" aria-label="Skill name" class="form-input">'
                        . '<select name="proficiency" aria-label="Proficiency" class="form-select"><option value="">Proficiency</option>' . $profOpts . '</select>'
                        . '<input type="number" name="years" min="0" max="60" step="0.5" placeholder="Years" aria-label="Years of experience" class="form-input">'
                        . '<div><button class="btn btn-secondary btn-sm">Add skill</button></div>'
                        . '</form>';
                }

                if ($skills === []) {
                    $html .= '<p class="text-sm text-slate-500">No skills recorded yet.</p>';
                    return $html;
                }

                $html .= '<ul class="flex flex-wrap gap-2">';
                foreach ($skills as $s) {
                    $label = e($s->name) . ' · ' . e($s->proficiencyLabel()) . ($s->years !== null ? ' (' . e((string) $s->years) . 'y)' : '');
                    $html .= '<li class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700">'
                        . '<span>' . $label . '</span>';
                    if ($canSkills) {
                        $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/skills/' . $s->skillId . '"'
                            . ' data-confirm="Remove this skill?" class="inline">'
                            . csrf_field() . '<input type="hidden" name="_method" value="DELETE">'
                            . '<button class="text-slate-400 hover:text-red-600" aria-label="Remove ' . e($s->name) . '">&times;</button></form>';
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
                return $html;
            })()]) ?>
        </div>

        <div id="passports" class="mt-4">
            <?= component('card', ['title' => 'Passports', 'body' => (function () use ($candidate, $passports, $canPassport) {
                $html = '';

                if ($canPassport) {
                    $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/passports" class="mb-4 grid gap-2 sm:grid-cols-3" data-once>'
                        . csrf_field()
                        . '<input type="text" name="passport_number" required maxlength="30" placeholder="Passport number" aria-label="Passport number" class="form-input">'
                        . '<input type="text" name="place_of_issue" maxlength="120" placeholder="Place of issue" aria-label="Place of issue" class="form-input">'
                        . '<input type="text" name="nationality" maxlength="2" placeholder="Nationality (e.g. IN)" aria-label="Nationality" class="form-input">'
                        . '<input type="date" name="issue_date" aria-label="Issue date" class="form-input">'
                        . '<input type="date" name="expiry_date" aria-label="Expiry date" class="form-input">'
                        . '<select name="held_by" aria-label="Held by" class="form-select">'
                        . '<option value="candidate">Held by candidate</option><option value="agency">Held by agency</option>'
                        . '<option value="employer">Held by employer</option><option value="embassy">Held by embassy</option>'
                        . '</select>'
                        . '<label class="flex items-center gap-1.5 text-xs text-slate-600 sm:col-span-3"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1"> Make this the primary passport</label>'
                        . '<div><button class="btn btn-secondary btn-sm">Add passport</button></div>'
                        . '</form>';
                }

                if ($passports === []) {
                    $html .= '<p class="text-sm text-slate-500">No passports on file.</p>';
                    return $html;
                }

                $html .= '<ul class="divide-y divide-slate-100">';
                foreach ($passports as $p) {
                    $badge = '';
                    if ($p->isExpired()) {
                        $badge = component('badge', ['label' => 'Expired', 'color' => 'red', 'dot' => true]);
                    } elseif ($p->isExpiringSoon()) {
                        $badge = component('badge', ['label' => 'Expiring soon', 'color' => 'amber', 'dot' => true]);
                    } elseif ($p->expiryDate !== null) {
                        $badge = component('badge', ['label' => 'Valid', 'color' => 'green']);
                    }
                    $meta = implode(' · ', array_filter([
                        $p->nationality, $p->placeOfIssue,
                        $p->expiryDate ? 'Expires ' . $p->expiryDate : null,
                        $p->heldBy !== 'candidate' ? 'Held by ' . $p->heldByLabel() : null,
                    ]));
                    $html .= '<li class="py-2.5 text-sm">'
                        . '<div class="flex items-start justify-between gap-2">'
                        . '<div><p class="font-medium text-slate-900">' . e($p->passportNumber)
                        . ($p->isPrimary ? ' ' . component('badge', ['label' => 'Primary', 'color' => 'indigo']) : '')
                        . ($badge !== '' ? ' ' . $badge : '') . '</p>'
                        . ($meta !== '' ? '<p class="text-xs text-slate-500">' . e($meta) . '</p>' : '')
                        . '</div>';

                    if ($canPassport) {
                        $html .= '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/passports/' . $p->id . '"'
                            . ' data-confirm="Remove this passport record?">'
                            . csrf_field() . '<input type="hidden" name="_method" value="DELETE">'
                            . '<button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>';
                    }
                    $html .= '</div>';

                    if ($canPassport) {
                        $html .= '<details class="mt-1.5"><summary class="cursor-pointer text-xs text-brand-600">Edit</summary>'
                            . '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/passports/' . $p->id . '"'
                            . ' class="mt-2 grid gap-2 sm:grid-cols-3">'
                            . csrf_field() . '<input type="hidden" name="_method" value="PUT">'
                            . '<input type="text" name="passport_number" required maxlength="30" value="' . e_attr($p->passportNumber) . '" aria-label="Passport number" class="form-input">'
                            . '<input type="text" name="place_of_issue" maxlength="120" value="' . e_attr($p->placeOfIssue ?? '') . '" aria-label="Place of issue" class="form-input">'
                            . '<input type="text" name="nationality" maxlength="2" value="' . e_attr($p->nationality ?? '') . '" aria-label="Nationality" class="form-input">'
                            . '<input type="date" name="issue_date" value="' . e_attr($p->issueDate ?? '') . '" aria-label="Issue date" class="form-input">'
                            . '<input type="date" name="expiry_date" value="' . e_attr($p->expiryDate ?? '') . '" aria-label="Expiry date" class="form-input">'
                            . '<select name="held_by" aria-label="Held by" class="form-select">'
                            . implode('', array_map(
                                static fn (string $v, string $l): string => '<option value="' . $v . '"' . ($p->heldBy === $v ? ' selected' : '') . '>' . $l . '</option>',
                                ['candidate', 'agency', 'employer', 'embassy'],
                                ['Held by candidate', 'Held by agency', 'Held by employer', 'Held by embassy'],
                            ))
                            . '</select>'
                            . '<label class="flex items-center gap-1.5 text-xs text-slate-600 sm:col-span-3"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1"' . ($p->isPrimary ? ' checked' : '') . '> Primary passport</label>'
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

        <div id="preferences">
            <?= component('card', ['title' => 'Preferences', 'body' => (function () use ($candidate, $preferences, $canPreferences) {
                if (!$canPreferences && $preferences === null) {
                    return '<p class="text-sm text-slate-500">No preferences recorded yet.</p>';
                }

                $countriesVal = $preferences !== null ? implode(', ', $preferences->preferredCountries) : '';
                $titlesVal = $preferences !== null ? implode(', ', $preferences->preferredJobTitles) : '';
                $relocate = $preferences === null || $preferences->willingToRelocate;
                $passportReady = $preferences !== null && $preferences->passportReady;

                if (!$canPreferences) {
                    $html = '<dl class="grid gap-y-2 text-sm">';
                    $html .= '<div><dt class="text-slate-500">Preferred countries</dt><dd class="text-slate-900">' . e($countriesVal ?: '—') . '</dd></div>';
                    $html .= '<div><dt class="text-slate-500">Preferred roles</dt><dd class="text-slate-900">' . e($titlesVal ?: '—') . '</dd></div>';
                    $html .= '<div><dt class="text-slate-500">Willing to relocate</dt><dd class="text-slate-900">' . ($relocate ? 'Yes' : 'No') . '</dd></div>';
                    $html .= '<div><dt class="text-slate-500">Passport ready</dt><dd class="text-slate-900">' . ($passportReady ? 'Yes' : 'No') . '</dd></div>';
                    $html .= '</dl>';
                    return $html;
                }

                $minSalary = $preferences?->minExpectedSalary;
                $currency = $preferences?->salaryCurrency ?? '';
                $availableFrom = $preferences?->availableFrom ?? '';
                $notes = $preferences?->notes ?? '';

                return '<form method="post" action="/candidates/' . e_attr($candidate->publicId) . '/preferences" class="space-y-2">'
                    . csrf_field() . '<input type="hidden" name="_method" value="PUT">'
                    . '<input type="text" name="preferred_countries" value="' . e_attr($countriesVal) . '" placeholder="Preferred countries (AE, SA, ...)" aria-label="Preferred countries" class="form-input w-full">'
                    . '<input type="text" name="preferred_job_titles" value="' . e_attr($titlesVal) . '" placeholder="Preferred job titles (comma-separated)" aria-label="Preferred job titles" class="form-input w-full">'
                    . '<div class="grid grid-cols-2 gap-2">'
                    . '<input type="number" name="min_expected_salary" min="0" step="0.01" value="' . e_attr((string) ($minSalary ?? '')) . '" placeholder="Min salary" aria-label="Minimum expected salary" class="form-input">'
                    . '<input type="text" name="salary_currency" maxlength="3" value="' . e_attr($currency) . '" placeholder="Currency (AED)" aria-label="Salary currency" class="form-input">'
                    . '</div>'
                    . '<input type="date" name="available_from" value="' . e_attr($availableFrom) . '" aria-label="Available from" class="form-input w-full">'
                    . '<label class="flex items-center gap-1.5 text-xs text-slate-600"><input type="hidden" name="willing_to_relocate" value="0"><input type="checkbox" name="willing_to_relocate" value="1"' . ($relocate ? ' checked' : '') . '> Willing to relocate</label>'
                    . '<label class="flex items-center gap-1.5 text-xs text-slate-600"><input type="hidden" name="passport_ready" value="0"><input type="checkbox" name="passport_ready" value="1"' . ($passportReady ? ' checked' : '') . '> Passport ready</label>'
                    . '<textarea name="notes" maxlength="500" placeholder="Notes" aria-label="Preference notes" class="form-textarea w-full" rows="2">' . e($notes) . '</textarea>'
                    . '<button class="btn btn-secondary btn-sm">Save preferences</button>'
                    . '</form>';
            })()]) ?>
        </div>
    </div>
</div>
<?php $this->stop(); ?>
