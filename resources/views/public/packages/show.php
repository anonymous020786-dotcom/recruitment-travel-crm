<?php
/** @var array<string,mixed> $pkg @var string $slug */

use App\Support\HtmlSanitizer;
use App\Support\PublicFormat;

$org = (string) config('seo.organization_name', config('app.name'));
$site = rtrim((string) config('app.url', ''), '/');
$duration = PublicFormat::duration($pkg['duration_days'], $pkg['duration_nights']);
$price = PublicFormat::price($pkg['price'], $pkg['currency']);
$this->layout('layouts.public', [
    'title' => $pkg['name'] . ' — ' . $pkg['destination'],
    'description' => mb_substr($pkg['name'] . ': ' . $pkg['destination'] . ($duration ? ', ' . $duration : '') . ($price ? ', from ' . $price . ' per person' : '') . '. ' . PublicFormat::excerpt($pkg['inclusions_html'], 80) . ' Enquire with ' . $org . '.', 0, 160),
    'canonical' => 'travel-packages/' . $slug,
]);
$this->start('head');
?>
    <script type="application/ld+json"><?= PublicFormat::json(PublicFormat::tripLd($pkg, $org, $site . '/travel-packages/' . $slug)) ?></script>
<?php
$this->stop();
$this->start('content');

$facts = array_filter([
    'Destination' => $pkg['destination'],
    'Duration' => $duration,
    'Starts from' => $pkg['start_location'] ?: null,
    'Price' => $price ? $price . ' per person' : null,
    'Hotels' => $pkg['hotel_summary'] ?: null,
    'Transport' => $pkg['transport_summary'] ?: null,
    'Meals' => $pkg['meals_summary'] ?: null,
]);
$sections = ['inclusions_html' => 'What is included', 'exclusions_html' => 'What is not included', 'terms_html' => 'Terms'];
?>
<article class="mx-auto max-w-3xl px-4 py-12">
    <p class="text-sm"><a href="/travel-packages" class="text-brand-600 hover:underline">← All travel packages</a></p>
    <h1 class="mt-2 text-2xl font-bold text-slate-900"><?= e($pkg['name']) ?></h1>
    <p class="mt-1 text-slate-600"><?= e($pkg['destination']) ?></p>

    <div class="mt-5"><a href="/travel-packages/<?= e_attr($slug) ?>/enquire" class="btn btn-primary">Enquire about this package</a></div>

    <h2 class="mt-8 text-base font-semibold text-slate-900">Package details</h2>
    <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <?php foreach ($facts as $label => $value): ?>
            <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500"><?= e($label) ?></dt><dd class="text-sm text-slate-900"><?= e($value) ?></dd></div>
        <?php endforeach ?>
    </dl>

    <?php if ($pkg['itinerary'] !== []): ?>
        <h2 class="mt-8 text-base font-semibold text-slate-900">Itinerary</h2>
        <ol class="mt-3 space-y-3">
            <?php foreach ($pkg['itinerary'] as $item): ?>
                <li class="card card-body">
                    <p class="font-medium text-slate-900"><?= $item['day_no'] ? 'Day ' . (int) $item['day_no'] . ' — ' : '' ?><?= e($item['title']) ?></p>
                    <?php if (!empty($item['description'])): ?><p class="mt-1 text-sm text-slate-600"><?= e($item['description']) ?></p><?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>
    <?php endif ?>

    <?php foreach ($sections as $field => $heading): ?>
        <?php if (!empty($pkg[$field])): ?>
            <h2 class="mt-8 text-base font-semibold text-slate-900"><?= e($heading) ?></h2>
            <div class="mt-2 space-y-3 text-sm text-slate-700"><?= HtmlSanitizer::clean((string) $pkg[$field]) ?></div>
        <?php endif ?>
    <?php endforeach ?>

    <div class="mt-10 card card-body">
        <p class="font-medium text-slate-900">Ready to book or have questions?</p>
        <p class="mt-1 text-sm text-slate-600">Send an enquiry and our travel team will call you with availability and the best price.</p>
        <p class="mt-3"><a href="/travel-packages/<?= e_attr($slug) ?>/enquire" class="btn btn-primary">Enquire about this package</a></p>
    </div>
</article>
<?php $this->stop(); ?>
