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
            <a href="/overseas-jobs" class="btn btn-primary">Browse overseas jobs</a>
            <a href="/travel-packages" class="btn btn-secondary">Travel packages</a>
            <a href="/contact" class="btn btn-ghost">Talk to us</a>
        </div>
    </div>
</section>

<?php
$latestJobs = $latestJobs ?? [];
$latestPackages = $latestPackages ?? [];
?>
<?php if ($latestJobs !== []): ?>
<section class="border-t border-slate-200">
    <div class="mx-auto max-w-6xl px-4 py-12">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="text-xl font-semibold text-slate-900">Latest overseas jobs</h2>
            <a href="/overseas-jobs" class="text-sm text-brand-600 hover:underline">See all jobs</a>
        </div>
        <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($latestJobs as $j): ?>
                <li class="card card-body">
                    <h3 class="text-base font-semibold text-slate-900"><a href="/overseas-jobs/<?= e_attr($j['slug']) ?>" class="hover:underline"><?= e($j['title']) ?></a></h3>
                    <p class="mt-1 text-sm text-slate-600"><?= e($j['country_name']) ?><?= !empty($j['city']) ? ' · ' . e($j['city']) : '' ?></p>
                    <?php if ($s = \App\Support\PublicFormat::salary($j['salary_min'], $j['salary_max'], $j['currency'])): ?><p class="mt-1 text-sm font-medium text-slate-800"><?= e($s) ?></p><?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
</section>
<?php endif ?>

<?php if ($latestPackages !== []): ?>
<section class="border-t border-slate-200">
    <div class="mx-auto max-w-6xl px-4 py-12">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="text-xl font-semibold text-slate-900">Travel packages</h2>
            <a href="/travel-packages" class="text-sm text-brand-600 hover:underline">See all packages</a>
        </div>
        <ul class="mt-4 grid gap-4 sm:grid-cols-3">
            <?php foreach ($latestPackages as $p): ?>
                <li class="card card-body">
                    <h3 class="text-base font-semibold text-slate-900"><a href="/travel-packages/<?= e_attr($p['slug']) ?>" class="hover:underline"><?= e($p['name']) ?></a></h3>
                    <p class="mt-1 text-sm text-slate-600"><?= e($p['destination']) ?><?php if ($d = \App\Support\PublicFormat::duration($p['duration_days'], $p['duration_nights'])): ?> · <?= e($d) ?><?php endif ?></p>
                    <?php if ($pr = \App\Support\PublicFormat::price($p['price'], $p['currency'])): ?><p class="mt-1 text-sm font-medium text-slate-800">From <?= e($pr) ?></p><?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
</section>
<?php endif ?>

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
