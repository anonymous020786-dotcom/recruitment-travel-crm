<?php
/** @var \App\Models\Candidate $candidate @var array<string,string> $countries */
$this->layout('layouts.app', ['title' => 'Edit ' . $candidate->fullName, 'currentPath' => '/candidates']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Edit ' . $candidate->fullName,
    'subtitle' => $candidate->candidateNumber,
    'breadcrumbs' => [
        ['label' => 'Candidates', 'href' => '/candidates'],
        ['label' => $candidate->candidateNumber, 'href' => '/candidates/' . $candidate->publicId],
        ['label' => 'Edit'],
    ],
]) ?>

<form method="post" action="/candidates/<?= e_attr($candidate->publicId) ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">
    <input type="hidden" name="record_version" value="<?= (int) $candidate->recordVersion ?>">

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="card card-body">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">Identity</h2>
            <?= component('field', ['name' => 'full_name', 'label' => 'Full name', 'required' => true, 'value' => old('full_name', $candidate->fullName)]) ?>
            <?= component('field', [
                'name' => 'gender', 'label' => 'Gender', 'control' => 'select', 'placeholder' => '—',
                'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other', 'undisclosed' => 'Undisclosed'],
                'value' => old('gender', $candidate->gender ?? ''),
            ]) ?>
            <?= component('field', ['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date', 'value' => old('date_of_birth', $candidate->dateOfBirth ?? '')]) ?>
            <?= component('field', ['name' => 'nationality', 'label' => 'Nationality', 'control' => 'select', 'placeholder' => '—', 'options' => $countries, 'value' => old('nationality', $candidate->nationality ?? '')]) ?>
            <?= component('field', ['name' => 'marital_status', 'label' => 'Marital status', 'control' => 'select', 'placeholder' => '—',
                'options' => ['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced', 'widowed' => 'Widowed'],
                'value' => old('marital_status', $candidate->maritalStatus ?? ''),
            ]) ?>
        </div>

        <div class="card card-body">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">Contact</h2>
            <?= component('field', ['name' => 'primary_phone', 'label' => 'Phone', 'required' => true, 'value' => old('primary_phone', $candidate->primaryPhone ?? '')]) ?>
            <?= component('field', ['name' => 'alternate_phone', 'label' => 'Alternate phone', 'value' => old('alternate_phone', $candidate->alternatePhone ?? '')]) ?>
            <?= component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => old('email', $candidate->email ?? '')]) ?>
            <?= component('field', ['name' => 'city', 'label' => 'City', 'value' => old('city', $candidate->city ?? '')]) ?>
            <?= component('field', ['name' => 'state', 'label' => 'State', 'value' => old('state', $candidate->state ?? '')]) ?>
            <?= component('field', ['name' => 'country', 'label' => 'Country of residence', 'control' => 'select', 'placeholder' => '—', 'options' => $countries, 'value' => old('country', $candidate->country ?? '')]) ?>
        </div>

        <div class="card card-body lg:col-span-2">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">Recruitment</h2>
            <div class="grid gap-x-4 sm:grid-cols-3">
                <?= component('field', ['name' => 'current_country', 'label' => 'Currently working in', 'control' => 'select', 'placeholder' => '—', 'options' => $countries, 'value' => old('current_country', $candidate->currentCountry ?? '')]) ?>
                <?= component('field', ['name' => 'highest_qualification', 'label' => 'Highest qualification', 'value' => old('highest_qualification', $candidate->highestQualification ?? '')]) ?>
                <?= component('field', ['name' => 'total_experience_years', 'label' => 'Total experience (years)', 'type' => 'number', 'attrs' => 'step="0.5" min="0" max="60"', 'value' => old('total_experience_years', (string) ($candidate->totalExperienceYears ?? ''))]) ?>
            </div>
        </div>
    </div>

    <div class="mt-4 flex gap-2">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="/candidates/<?= e_attr($candidate->publicId) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
