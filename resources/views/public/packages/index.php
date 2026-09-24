<?php
/** @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $perPage @var string $search */

use App\Support\PublicFormat;

$org = (string) config('seo.organization_name', config('app.name'));
$this->layout('layouts.public', [
    'title' => 'Travel packages' . ($page > 1 ? " — page {$page}" : ''),
    'description' => 'Holiday and tour packages from ' . $org . ': destinations, duration, what is included and price. Enquire online and our team will help you plan.',
    'canonical' => 'travel-packages' . ($page > 1 && $search === '' ? '?page=' . $page : ''),
    'robots' => $search !== '' ? 'noindex, follow' : null,
]);
$this->start('content');
?>
<section class="mx-auto max-w-5xl px-4 py-12">
    <h1 class="text-2xl font-bold text-slate-900">Travel packages</h1>
    <p class="mt-1 text-sm text-slate-600"><?= number_format($total) ?> package<?= $total === 1 ? '' : 's' ?> available. Choose one to see the itinerary and enquire.</p>

    <form method="get" action="/travel-packages" class="mt-5 flex flex-wrap items-end gap-3" role="search">
        <div>
            <label class="form-label" for="q">Search by destination or package name</label>
            <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($search) ?>" maxlength="80">
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search !== ''): ?><a class="btn btn-ghost" href="/travel-packages">Clear</a><?php endif ?>
    </form>

    <?php if ($rows === []): ?>
        <div class="mt-8"><?= component('card', ['body' => component('empty-state', [
            'title' => 'No packages match right now',
            'message' => 'Tell us where you want to go and we will put a plan together for you.',
            'action' => '<a class="btn btn-primary" href="/contact">Contact us</a>',
        ])]) ?></div>
    <?php else: ?>
        <ul class="mt-6 grid gap-4 sm:grid-cols-2">
            <?php foreach ($rows as $p): ?>
                <li class="card card-body">
                    <h2 class="text-base font-semibold text-slate-900"><a href="/travel-packages/<?= e_attr($p['slug']) ?>" class="hover:underline"><?= e($p['name']) ?></a></h2>
                    <p class="mt-1 text-sm text-slate-600"><?= e($p['destination']) ?><?php if ($d = PublicFormat::duration($p['duration_days'], $p['duration_nights'])): ?> · <?= e($d) ?><?php endif ?></p>
                    <?php if ($price = PublicFormat::price($p['price'], $p['currency'])): ?><p class="mt-2 text-sm font-medium text-slate-800">From <?= e($price) ?> per person</p><?php endif ?>
                    <?php if ($ex = PublicFormat::excerpt($p['inclusions_html'], 140)): ?><p class="mt-2 text-sm text-slate-600"><?= e($ex) ?></p><?php endif ?>
                    <p class="mt-3"><a class="btn btn-secondary btn-sm" href="/travel-packages/<?= e_attr($p['slug']) ?>">View package<span class="sr-only">: <?= e($p['name']) ?></span></a></p>
                </li>
            <?php endforeach ?>
        </ul>
        <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/travel-packages', 'query' => $search !== '' ? ['q' => $search] : []]) ?>
    <?php endif ?>
</section>
<?php $this->stop(); ?>
