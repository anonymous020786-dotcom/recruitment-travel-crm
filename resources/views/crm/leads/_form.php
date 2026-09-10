<?php
/**
 * @var \App\Models\Lead|null $lead
 * @var array $sources @var array $assignees @var array<string,string> $countries
 */
$lead = $lead ?? null;
$val = static fn (string $k, $fallback = '') => old($k, $lead !== null ? ($lead->raw[$k] ?? $fallback) : $fallback);
?>
<div class="grid gap-x-5 gap-y-0 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <?= component('field', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'value' => $val('name'), 'attrs' => 'maxlength="150" autofocus']) ?>
    </div>

    <?= component('field', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true, 'value' => $val('phone'), 'attrs' => 'maxlength="30"']) ?>
    <?= component('field', ['name' => 'alternate_phone', 'label' => 'Alternate phone', 'type' => 'tel', 'value' => $val('alternate_phone'), 'attrs' => 'maxlength="30"']) ?>

    <?= component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $val('email'), 'attrs' => 'maxlength="180"']) ?>
    <?= component('field', [
        'name' => 'priority', 'label' => 'Priority', 'control' => 'select', 'required' => true,
        'value' => $val('priority', 'medium'),
        'options' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'],
    ]) ?>

    <?= component('field', [
        'name' => 'gender', 'label' => 'Gender', 'control' => 'select', 'placeholder' => '—',
        'value' => $val('gender'),
        'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other', 'undisclosed' => 'Prefer not to say'],
    ]) ?>
    <?= component('field', ['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date', 'value' => $val('date_of_birth')]) ?>

    <?= component('field', ['name' => 'city', 'label' => 'City', 'value' => $val('city'), 'attrs' => 'maxlength="90"']) ?>
    <?= component('field', ['name' => 'state', 'label' => 'State', 'value' => $val('state'), 'attrs' => 'maxlength="90"']) ?>

    <?= component('field', [
        'name' => 'source_id', 'label' => 'Source', 'control' => 'select', 'placeholder' => '—',
        'value' => $val('source_id'),
        'options' => array_column($sources, 'name', 'id'),
    ]) ?>
    <?= component('field', ['name' => 'campaign', 'label' => 'Campaign', 'value' => $val('campaign'), 'attrs' => 'maxlength="120"']) ?>

    <?= component('field', [
        'name' => 'interested_country', 'label' => 'Interested country', 'control' => 'select', 'placeholder' => '—',
        'value' => $val('interested_country'),
        'options' => $countries,
    ]) ?>
    <?= component('field', ['name' => 'interested_job', 'label' => 'Interested job', 'value' => $val('interested_job'), 'attrs' => 'maxlength="120"']) ?>

    <?= component('field', ['name' => 'experience_years', 'label' => 'Experience (years)', 'type' => 'number', 'value' => $val('experience_years'), 'attrs' => 'step="0.5" min="0" max="60"']) ?>
    <?= component('field', ['name' => 'qualification', 'label' => 'Qualification', 'value' => $val('qualification'), 'attrs' => 'maxlength="120"']) ?>

    <?= component('field', ['name' => 'salary_expectation', 'label' => 'Salary expectation', 'type' => 'number', 'value' => $val('salary_expectation'), 'attrs' => 'step="0.01" min="0"']) ?>
    <?= component('field', ['name' => 'salary_currency', 'label' => 'Currency', 'value' => $val('salary_currency'), 'attrs' => 'maxlength="3" placeholder="AED"']) ?>

    <div class="sm:col-span-2">
        <?= component('field', [
            'name' => 'assigned_to', 'label' => 'Assign to', 'control' => 'select', 'placeholder' => 'Unassigned',
            'value' => $val('assigned_to'),
            'options' => array_column($assignees, 'name', 'id'),
        ]) ?>
    </div>

    <div class="sm:col-span-2">
        <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'control' => 'textarea', 'value' => $val('notes'), 'rows' => 3, 'attrs' => 'maxlength="5000"']) ?>
    </div>
</div>
