<?php
/**
 * @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $perPage
 * @var string $country @var ?string $countryName @var string $search @var list<array{code:string,name:string,n:int}> $countries
 */

use App\Support\PublicFormat;

$org = (string) setting('business.name', config('app.name'));
$where = $countryName !== null ? " in {$countryName}" : '';
$qs = array_filter(['country' => $country, 'q' => $search, 'page' => $page > 1 ? $page : null], static fn ($v): bool => $v !== '' && $v !== null);
$this->layout('layouts.public', [
    'title' => 'Overseas jobs' . $where . ($page > 1 ? " — page {$page}" : ''),
    'description' => 'Current overseas job openings' . $where . ' with ' . $org . ': salary, benefits, contract terms and how to apply. New vacancies added regularly.',
    'canonical' => 'overseas-jobs' . ($qs !== [] && $search === '' ? '?' . http_build_query($qs) : ''),
    'robots' => $search !== '' ? 'noindex, follow' : null,
]);
$this->start('content');
?>
<section class="mx-auto max-w-5xl px-4 py-12">
    <h1 class="text-2xl font-bold text-slate-900">Overseas jobs<?= e($where) ?></h1>
    <p class="mt-1 text-sm text-slate-600"><?= number_format($total) ?> open vacanc<?= $total === 1 ? 'y' : 'ies' ?>. Tap a job for the full details and to apply.</p>

    <form method="get" action="/overseas-jobs" class="mt-5 flex flex-wrap items-end gap-3" role="search">
        <div>
            <label class="form-label" for="q">Search by job title or city</label>
            <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($search) ?>" maxlength="80">
        </div>
        <?php if ($country !== ''): ?><input type="hidden" name="country" value="<?= e_attr($country) ?>"><?php endif ?>
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search !== ''): ?><a class="btn btn-ghost" href="/overseas-jobs<?= $country !== '' ? '?country=' . e_attr($country) : '' ?>">Clear</a><?php endif ?>
    </form>

    <?php if ($countries !== []): ?>
        <p class="mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500" id="by-country">Country</p>
        <ul class="mt-2 flex flex-wrap gap-2" aria-labelledby="by-country">
            <li><a href="/overseas-jobs" class="badge <?= $country === '' ? 'bg-brand-600 text-white ring-brand-600' : 'bg-white text-slate-700 ring-slate-300' ?>"<?= $country === '' ? ' aria-current="true"' : '' ?>>All</a></li>
            <?php foreach ($countries as $c): ?>
                <li><a href="/overseas-jobs?country=<?= e_attr($c['code']) ?>" class="badge <?= $country === $c['code'] ? 'bg-brand-600 text-white ring-brand-600' : 'bg-white text-slate-700 ring-slate-300' ?>"<?= $country === $c['code'] ? ' aria-current="true"' : '' ?>><?= e($c['name']) ?> (<?= (int) $c['n'] ?>)</a></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <?php if ($rows === []): ?>
        <div class="mt-8"><?= component('card', ['body' => component('empty-state', [
            'title' => 'No vacancies match right now',
            'message' => 'New jobs are added often. Send us your details and we will contact you when one fits.',
            'action' => '<a class="btn btn-primary" href="/contact">Contact us</a>',
        ])]) ?></div>
    <?php else: ?>
        <ul class="mt-6 grid gap-4 sm:grid-cols-2">
            <?php foreach ($rows as $j): ?>
                <li class="card card-body">
                    <h2 class="text-base font-semibold text-slate-900"><a href="/overseas-jobs/<?= e_attr($j['slug']) ?>"><?= e($j['title']) ?></a></h2>
                    <p class="mt-1 text-sm text-slate-600"><?= e($j['country_name']) ?><?= !empty($j['city']) ? ' · ' . e($j['city']) : '' ?> · <?= (int) $j['vacancies'] ?> vacanc<?= (int) $j['vacancies'] === 1 ? 'y' : 'ies' ?></p>
                    <?php if ($s = PublicFormat::salary($j['salary_min'], $j['salary_max'], $j['currency'])): ?><p class="mt-2 text-sm font-medium text-slate-800"><?= e($s) ?></p><?php endif ?>
                    <?php if (PublicFormat::benefits($j) !== []): ?><p class="mt-1 text-xs text-slate-500"><?= e(implode(' · ', PublicFormat::benefits($j))) ?></p><?php endif ?>
                    <?php if ($ex = PublicFormat::excerpt($j['description_html'], 140)): ?><p class="mt-2 text-sm text-slate-600"><?= e($ex) ?></p><?php endif ?>
                    <p class="mt-3"><a class="btn btn-secondary btn-sm" href="/overseas-jobs/<?= e_attr($j['slug']) ?>">View details<span class="sr-only"> for <?= e($j['title']) ?></span></a></p>
                </li>
            <?php endforeach ?>
        </ul>
        <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/overseas-jobs', 'query' => array_filter(['country' => $country, 'q' => $search], static fn ($v): bool => $v !== '')]) ?>
    <?php endif ?>
</section>
<?php $this->stop(); ?>
