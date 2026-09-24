<?php
/** @var string $q @var bool $tooShort @var list<array{key:string,title:string,listUrl:string,total:int,hits:list<array{title:string,subtitle:string,url:string}>}> $sections */
$this->layout('layouts.app', ['title' => $q !== '' ? 'Search: ' . $q : 'Search', 'currentPath' => '/search']);
$this->start('content');
?>
<?= component('page-header', ['title' => 'Search', 'subtitle' => 'Leads, candidates, applications, employers, jobs, invoices, payments, bookings and website enquiries you have access to.']) ?>

<form method="get" action="/search" role="search" class="mb-6 flex max-w-xl gap-2">
    <div class="flex-1">
        <label class="sr-only" for="search-q">Search</label>
        <input class="form-input" type="search" id="search-q" name="q" value="<?= e_attr($q) ?>" maxlength="60" placeholder="Name, phone, email or number (LEAD-, CAND-, INV-…)" autofocus>
    </div>
    <button type="submit" class="btn btn-primary">Search</button>
</form>

<?php if ($q === ''): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'Search everything', 'message' => 'Type a name, a phone number, an email or a reference number.'])]) ?>
<?php elseif ($tooShort): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'Keep typing', 'message' => 'Enter at least ' . \App\Services\GlobalSearchService::MIN_LENGTH . ' characters.'])]) ?>
<?php elseif ($sections === []): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No results for “' . $q . '”', 'message' => 'Check the spelling, or try part of the name or the last digits of a phone number.'])]) ?>
<?php else: ?>
    <div class="grid gap-4 lg:grid-cols-2">
        <?php foreach ($sections as $s): ?>
            <section class="card" aria-labelledby="sec-<?= e_attr($s['key']) ?>">
                <div class="flex items-baseline justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-slate-900" id="sec-<?= e_attr($s['key']) ?>"><?= e($s['title']) ?> <span class="font-normal text-slate-500">(<?= (int) $s['total'] ?>)</span></h2>
                    <?php if ($s['total'] > count($s['hits'])): ?><a href="<?= e_attr($s['listUrl']) ?>" class="text-xs text-brand-600 hover:underline">See all <?= (int) $s['total'] ?><span class="sr-only"> <?= e(strtolower($s['title'])) ?></span></a><?php endif ?>
                </div>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($s['hits'] as $h): ?>
                        <li><a href="<?= e_attr($h['url']) ?>" class="block px-4 py-2.5 hover:bg-slate-50">
                            <span class="block text-sm font-medium text-slate-900"><?= e($h['title']) ?></span>
                            <span class="block text-xs text-slate-500"><?= e($h['subtitle']) ?></span>
                        </a></li>
                    <?php endforeach ?>
                </ul>
            </section>
        <?php endforeach ?>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
