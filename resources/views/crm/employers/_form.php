<?php
/**
 * @var \App\Models\Employer|null $employer
 * @var array<string,string> $countries @var list<array{id:int,name:string}> $owners
 */
$employer = $employer ?? null;
$val = static function (string $k, ?string $fallback = '') use ($employer): string {
    $map = $employer === null ? [] : [
        'company_name' => $employer->companyName, 'country' => $employer->country, 'city' => $employer->city,
        'address' => $employer->address, 'industry' => $employer->industry, 'website' => $employer->website,
        'license_number' => $employer->licenseNumber, 'license_expiry' => $employer->licenseExpiry,
        'status' => $employer->status, 'account_owner' => $employer->accountOwner, 'notes' => $employer->notes,
    ];

    return (string) old($k, $map[$k] ?? $fallback);
};
?>
<div class="grid gap-x-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <?= component('field', ['name' => 'company_name', 'label' => 'Company name', 'required' => true, 'value' => $val('company_name'), 'attrs' => 'maxlength="180" autofocus']) ?>
    </div>
    <?= component('field', ['name' => 'country', 'label' => 'Country', 'control' => 'select', 'required' => true, 'placeholder' => 'Select…', 'options' => $countries, 'value' => $val('country')]) ?>
    <?= component('field', ['name' => 'city', 'label' => 'City', 'value' => $val('city'), 'attrs' => 'maxlength="90"']) ?>
    <div class="sm:col-span-2">
        <?= component('field', ['name' => 'address', 'label' => 'Address', 'value' => $val('address'), 'attrs' => 'maxlength="255"']) ?>
    </div>
    <?= component('field', ['name' => 'industry', 'label' => 'Industry', 'value' => $val('industry'), 'attrs' => 'maxlength="120"']) ?>
    <?= component('field', ['name' => 'website', 'label' => 'Website', 'type' => 'url', 'value' => $val('website'), 'attrs' => 'maxlength="180" placeholder="https://"']) ?>
    <?= component('field', ['name' => 'license_number', 'label' => 'License number', 'value' => $val('license_number'), 'attrs' => 'maxlength="80"']) ?>
    <?= component('field', ['name' => 'license_expiry', 'label' => 'License expiry', 'type' => 'date', 'value' => $val('license_expiry')]) ?>
    <?= component('field', [
        'name' => 'status', 'label' => 'Status', 'control' => 'select', 'value' => $val('status', 'active'),
        'options' => ['prospect' => 'Prospect', 'active' => 'Active', 'suspended' => 'Suspended', 'blacklisted' => 'Blacklisted', 'inactive' => 'Inactive'],
    ]) ?>
    <?= component('field', ['name' => 'account_owner', 'label' => 'Account owner', 'control' => 'select', 'placeholder' => '—', 'options' => array_column($owners, 'name', 'id'), 'value' => $val('account_owner')]) ?>
    <div class="sm:col-span-2">
        <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'control' => 'textarea', 'rows' => 3, 'value' => $val('notes'), 'attrs' => 'maxlength="5000"']) ?>
    </div>
</div>
