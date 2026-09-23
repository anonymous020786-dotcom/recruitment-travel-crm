<?php
/**
 * Trip fields shared by create and edit. The customer block is create-only.
 * @var \App\Models\TourBooking|null $booking @var array<string,string> $packages @var array<int|string,string> $assignees
 */
$booking = $booking ?? null;
$selectedPackage = $selectedPackage ?? '';
$val = static function (string $k, ?string $fallback = '') use ($booking): string {
    $map = $booking === null ? [] : [
        'package' => $booking->packagePublicId, 'travel_date' => $booking->travelDate, 'return_date' => $booking->returnDate,
        'adults' => $booking->adults, 'children' => $booking->children, 'total_amount' => $booking->totalAmount,
        'currency' => $booking->currency, 'assigned_to' => $booking->assignedTo, 'notes' => $booking->notes,
    ];

    return (string) old($k, $map[$k] ?? $fallback);
};
?>
<?php if ($booking === null): ?>
    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Customer</h3>
    <div class="grid gap-x-5 sm:grid-cols-3">
        <?= component('field', ['name' => 'customer_name', 'label' => 'Full name', 'required' => true, 'value' => (string) old('customer_name', ''), 'attrs' => 'maxlength="150" autofocus']) ?>
        <?= component('field', ['name' => 'customer_phone', 'label' => 'Phone', 'required' => true, 'value' => (string) old('customer_phone', ''), 'attrs' => 'maxlength="30" inputmode="tel"']) ?>
        <?= component('field', ['name' => 'customer_email', 'label' => 'Email', 'type' => 'email', 'value' => (string) old('customer_email', ''), 'attrs' => 'maxlength="180"']) ?>
    </div>
    <p class="mb-4 -mt-2 text-xs text-slate-500">If the phone or email already belongs to a customer, candidate or lead, that person is reused instead of creating a duplicate.</p>
    <?php if (!empty($branches)): ?>
        <div class="grid gap-x-5 sm:grid-cols-3">
            <?= component('field', [
                'name' => 'branch_id', 'label' => 'Branch', 'control' => 'select', 'required' => true, 'placeholder' => 'Select…',
                'options' => $branches, 'value' => (string) old('branch_id', (string) ($defaultBranch ?? '')),
            ]) ?>
        </div>
    <?php endif ?>
<?php endif ?>

<h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Trip</h3>
<div class="grid gap-x-5 sm:grid-cols-3">
    <div class="sm:col-span-2">
        <?= component('field', ['name' => 'package', 'label' => 'Package', 'control' => 'select', 'placeholder' => 'Custom trip (no package)', 'options' => $packages, 'value' => $val('package', $selectedPackage)]) ?>
    </div>
    <?= component('field', ['name' => 'assigned_to', 'label' => 'Handled by', 'control' => 'select', 'placeholder' => 'Me', 'options' => $assignees, 'value' => $val('assigned_to')]) ?>

    <?= component('field', ['name' => 'travel_date', 'label' => 'Travel date', 'type' => 'date', 'value' => $val('travel_date')]) ?>
    <?= component('field', ['name' => 'return_date', 'label' => 'Return date', 'type' => 'date', 'value' => $val('return_date')]) ?>
    <div></div>

    <?= component('field', ['name' => 'adults', 'label' => 'Adults', 'type' => 'number', 'required' => true, 'value' => $val('adults', '1'), 'attrs' => 'min="1" max="200"']) ?>
    <?= component('field', ['name' => 'children', 'label' => 'Children', 'type' => 'number', 'value' => $val('children', '0'), 'attrs' => 'min="0" max="200"']) ?>
    <div></div>

    <?= component('field', ['name' => 'total_amount', 'label' => 'Total amount', 'type' => 'number', 'value' => $val('total_amount'), 'hint' => 'Leave blank to price it from the package (price × travellers).', 'attrs' => 'min="0" step="0.01"']) ?>
    <?= component('field', ['name' => 'currency', 'label' => 'Currency', 'value' => $val('currency'), 'attrs' => 'maxlength="3" placeholder="INR"']) ?>
    <div></div>

    <div class="sm:col-span-3">
        <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'control' => 'textarea', 'rows' => 3, 'value' => $val('notes'), 'attrs' => 'maxlength="5000"']) ?>
    </div>
</div>
