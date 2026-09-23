<?php
/** @var \App\Models\Job|null $job @var array<string,string> $countries */
$job = $job ?? null;
$plain = static fn (?string $html): string => $html === null ? '' : html_entity_decode(strip_tags(str_replace(['</p><p>', '<br>', '<br />'], ["\n\n", "\n", "\n"], $html)), ENT_QUOTES, 'UTF-8');
$val = static function (string $k, ?string $fallback = '') use ($job, $plain): string {
    $map = $job === null ? [] : [
        'title' => $job->title, 'country' => $job->country, 'city' => $job->city, 'vacancies' => $job->vacancies,
        'salary_min' => $job->salaryMin, 'salary_max' => $job->salaryMax, 'currency' => $job->currency,
        'experience_required' => $job->experienceRequired, 'qualification' => $job->qualification,
        'age_min' => $job->ageMin, 'age_max' => $job->ageMax, 'gender_requirement' => $job->genderRequirement,
        'accommodation' => $job->accommodation, 'food' => $job->food, 'transport' => $job->transport,
        'working_hours' => $job->workingHours, 'overtime' => $job->overtime,
        'contract_duration_months' => $job->contractDurationMonths, 'interview_type' => $job->interviewType,
        'deadline' => $job->deadline, 'description' => $plain($job->descriptionHtml),
    ];

    return (string) old($k, $map[$k] ?? $fallback);
};
$provision = ['none' => 'Not provided', 'provided' => 'Provided', 'allowance' => 'Allowance'];
?>
<div class="grid gap-x-5 sm:grid-cols-3">
    <div class="sm:col-span-3">
        <?= component('field', ['name' => 'title', 'label' => 'Job title', 'required' => true, 'value' => $val('title'), 'attrs' => 'maxlength="160" autofocus']) ?>
    </div>
    <?= component('field', ['name' => 'country', 'label' => 'Country', 'control' => 'select', 'required' => true, 'placeholder' => 'Select…', 'options' => $countries, 'value' => $val('country')]) ?>
    <?= component('field', ['name' => 'city', 'label' => 'City', 'value' => $val('city'), 'attrs' => 'maxlength="90"']) ?>
    <?= component('field', ['name' => 'vacancies', 'label' => 'Vacancies', 'type' => 'number', 'required' => true, 'value' => $val('vacancies', '1'), 'attrs' => 'min="1" max="60000"']) ?>

    <?= component('field', ['name' => 'salary_min', 'label' => 'Salary from', 'type' => 'number', 'value' => $val('salary_min'), 'attrs' => 'min="0" step="0.01"']) ?>
    <?= component('field', ['name' => 'salary_max', 'label' => 'Salary to', 'type' => 'number', 'value' => $val('salary_max'), 'attrs' => 'min="0" step="0.01"']) ?>
    <?= component('field', ['name' => 'currency', 'label' => 'Currency', 'value' => $val('currency'), 'attrs' => 'maxlength="3" placeholder="AED"']) ?>

    <?= component('field', ['name' => 'experience_required', 'label' => 'Experience required', 'value' => $val('experience_required'), 'attrs' => 'maxlength="120" placeholder="e.g. 2+ years"']) ?>
    <?= component('field', ['name' => 'qualification', 'label' => 'Qualification', 'value' => $val('qualification'), 'attrs' => 'maxlength="120"']) ?>
    <?= component('field', ['name' => 'gender_requirement', 'label' => 'Gender', 'control' => 'select', 'value' => $val('gender_requirement', 'any'), 'options' => ['any' => 'Any', 'male' => 'Male', 'female' => 'Female']]) ?>

    <?= component('field', ['name' => 'age_min', 'label' => 'Minimum age', 'type' => 'number', 'value' => $val('age_min'), 'attrs' => 'min="14" max="80"']) ?>
    <?= component('field', ['name' => 'age_max', 'label' => 'Maximum age', 'type' => 'number', 'value' => $val('age_max'), 'attrs' => 'min="14" max="80"']) ?>
    <?= component('field', ['name' => 'contract_duration_months', 'label' => 'Contract (months)', 'type' => 'number', 'value' => $val('contract_duration_months'), 'attrs' => 'min="1" max="120"']) ?>

    <?= component('field', ['name' => 'accommodation', 'label' => 'Accommodation', 'control' => 'select', 'value' => $val('accommodation', 'none'), 'options' => $provision]) ?>
    <?= component('field', ['name' => 'food', 'label' => 'Food', 'control' => 'select', 'value' => $val('food', 'none'), 'options' => $provision]) ?>
    <?= component('field', ['name' => 'transport', 'label' => 'Transport', 'control' => 'select', 'value' => $val('transport', 'none'), 'options' => $provision]) ?>

    <?= component('field', ['name' => 'working_hours', 'label' => 'Working hours', 'value' => $val('working_hours'), 'attrs' => 'maxlength="60" placeholder="e.g. 8 hrs/day, 6 days"']) ?>
    <?= component('field', ['name' => 'overtime', 'label' => 'Overtime', 'value' => $val('overtime'), 'attrs' => 'maxlength="120"']) ?>
    <?= component('field', [
        'name' => 'interview_type', 'label' => 'Interview type', 'control' => 'select', 'placeholder' => '—', 'value' => $val('interview_type'),
        'options' => ['in_person' => 'In person', 'video' => 'Video', 'telephonic' => 'Telephonic', 'cv_selection' => 'CV selection', 'client_visit' => 'Client visit'],
    ]) ?>

    <?= component('field', ['name' => 'deadline', 'label' => 'Application deadline', 'type' => 'date', 'value' => $val('deadline')]) ?>
    <div class="sm:col-span-3">
        <?= component('field', ['name' => 'description', 'label' => 'Description', 'control' => 'textarea', 'rows' => 6, 'value' => $val('description'), 'hint' => 'Plain text; blank lines start a new paragraph.', 'attrs' => 'maxlength="20000"']) ?>
    </div>
</div>
