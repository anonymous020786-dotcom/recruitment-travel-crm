<?php
/** @var \App\Models\TourPackage|null $package */
$package = $package ?? null;
$plain = static fn (?string $html): string => $html === null ? '' : html_entity_decode(strip_tags(str_replace(['</p><p>', '<br>', '<br />'], ["\n\n", "\n", "\n"], $html)), ENT_QUOTES, 'UTF-8');
$val = static function (string $k, ?string $fallback = '') use ($package, $plain): string {
    $map = $package === null ? [] : [
        'name' => $package->name, 'destination' => $package->destination, 'duration_days' => $package->durationDays,
        'duration_nights' => $package->durationNights, 'start_location' => $package->startLocation, 'price' => $package->price,
        'currency' => $package->currency, 'hotel_summary' => $package->hotelSummary, 'transport_summary' => $package->transportSummary,
        'meals_summary' => $package->mealsSummary, 'inclusions' => $plain($package->inclusionsHtml),
        'exclusions' => $plain($package->exclusionsHtml), 'terms' => $plain($package->termsHtml),
    ];

    return (string) old($k, $map[$k] ?? $fallback);
};
?>
<div class="grid gap-x-5 sm:grid-cols-3">
    <div class="sm:col-span-2">
        <?= component('field', ['name' => 'name', 'label' => 'Package name', 'required' => true, 'value' => $val('name'), 'attrs' => 'maxlength="180" autofocus']) ?>
    </div>
    <?= component('field', ['name' => 'destination', 'label' => 'Destination', 'required' => true, 'value' => $val('destination'), 'attrs' => 'maxlength="120" placeholder="e.g. Dubai, UAE"']) ?>

    <?= component('field', ['name' => 'duration_days', 'label' => 'Days', 'type' => 'number', 'value' => $val('duration_days'), 'attrs' => 'min="1" max="365"']) ?>
    <?= component('field', ['name' => 'duration_nights', 'label' => 'Nights', 'type' => 'number', 'value' => $val('duration_nights'), 'attrs' => 'min="0" max="365"']) ?>
    <?= component('field', ['name' => 'start_location', 'label' => 'Departs from', 'value' => $val('start_location'), 'attrs' => 'maxlength="120"']) ?>

    <?= component('field', ['name' => 'price', 'label' => 'Price per person', 'type' => 'number', 'value' => $val('price'), 'attrs' => 'min="0" step="0.01"']) ?>
    <?= component('field', ['name' => 'currency', 'label' => 'Currency', 'value' => $val('currency'), 'attrs' => 'maxlength="3" placeholder="INR"']) ?>
    <div></div>

    <?= component('field', ['name' => 'hotel_summary', 'label' => 'Hotel', 'value' => $val('hotel_summary'), 'attrs' => 'maxlength="255" placeholder="e.g. 4-star, breakfast included"']) ?>
    <?= component('field', ['name' => 'transport_summary', 'label' => 'Transport', 'value' => $val('transport_summary'), 'attrs' => 'maxlength="255"']) ?>
    <?= component('field', ['name' => 'meals_summary', 'label' => 'Meals', 'value' => $val('meals_summary'), 'attrs' => 'maxlength="255"']) ?>

    <div class="sm:col-span-3">
        <?= component('field', ['name' => 'inclusions', 'label' => 'Inclusions', 'control' => 'textarea', 'rows' => 4, 'value' => $val('inclusions'), 'hint' => 'Plain text; blank lines start a new paragraph.', 'attrs' => 'maxlength="20000"']) ?>
    </div>
    <div class="sm:col-span-3">
        <?= component('field', ['name' => 'exclusions', 'label' => 'Exclusions', 'control' => 'textarea', 'rows' => 4, 'value' => $val('exclusions'), 'attrs' => 'maxlength="20000"']) ?>
    </div>
    <div class="sm:col-span-3">
        <?= component('field', ['name' => 'terms', 'label' => 'Terms & conditions', 'control' => 'textarea', 'rows' => 4, 'value' => $val('terms'), 'attrs' => 'maxlength="20000"']) ?>
    </div>
</div>
