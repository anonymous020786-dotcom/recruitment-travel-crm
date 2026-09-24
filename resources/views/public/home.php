<?php
$this->layout('layouts.public', [
    'title' => (string) config('seo.default_title'),
    'description' => (string) config('seo.default_description'),
    'canonical' => '',
]);
$this->start('content');
?>
<section class="mx-auto max-w-6xl px-4 py-16 sm:py-24">
    <div class="max-w-2xl">
        <h1 class="text-3xl font-bold text-slate-900 sm:text-4xl">
            Overseas jobs, recruitment &amp; travel — handled end to end.
        </h1>
        <p class="mt-4 text-lg text-slate-600">
            We help candidates find verified overseas employment and support employers
            through the full hiring lifecycle — documentation, interviews, medicals,
            visas and travel.
        </p>
        <div class="mt-6 flex flex-wrap gap-3">
            <a href="/contact" class="btn btn-primary">Talk to us</a>
            <a href="/about" class="btn btn-secondary">About us</a>
        </div>
    </div>
</section>

<section class="border-t border-slate-200 bg-white">
    <div class="mx-auto grid max-w-6xl gap-6 px-4 py-12 sm:grid-cols-3">
        <?php foreach ([
            ['Recruitment', 'Job matching, applications, interviews and selection tracking.'],
            ['Processing', 'Documents, medicals, visas and departure coordination.'],
            ['Travel', 'Tour packages, bookings and travel arrangements for the same customers.'],
        ] as [$h, $p]): ?>
            <div>
                <h2 class="text-base font-semibold text-slate-900"><?= e($h) ?></h2>
                <p class="mt-1 text-sm text-slate-600"><?= e($p) ?></p>
            </div>
        <?php endforeach ?>
    </div>
</section>
<?php $this->stop(); ?>
