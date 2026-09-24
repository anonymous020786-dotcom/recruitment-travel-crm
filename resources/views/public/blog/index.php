<?php
/** @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $perPage */

use App\Support\BlogFormatter;

$org = (string) setting('business.name', config('app.name'));
$this->layout('layouts.public', [
    'title' => 'Blog' . ($page > 1 ? " — page {$page}" : ''),
    'description' => 'Guides and news from ' . $org . ' on working abroad, recruitment, visas, medicals and travel.',
    'canonical' => 'blog' . ($page > 1 ? '?page=' . $page : ''),
]);
$this->start('content');
?>
<section class="mx-auto max-w-3xl px-4 py-12">
    <h1 class="text-2xl font-bold text-slate-900">Blog</h1>
    <p class="mt-1 text-sm text-slate-600">Guides and news on working abroad, visas, medicals and travel.</p>

    <?php if ($rows === []): ?>
        <div class="mt-8"><?= component('card', ['body' => component('empty-state', [
            'title' => 'Nothing published yet',
            'message' => 'New articles will appear here. In the meantime, see the latest jobs.',
            'action' => '<a class="btn btn-primary" href="/overseas-jobs">Browse jobs</a>',
        ])]) ?></div>
    <?php else: ?>
        <ul class="mt-6 space-y-4">
            <?php foreach ($rows as $p): ?>
                <li class="card card-body">
                    <h2 class="text-lg font-semibold text-slate-900"><a href="/blog/<?= e_attr($p['slug']) ?>" class="hover:underline"><?= e($p['title']) ?></a></h2>
                    <p class="mt-1 text-xs text-slate-500"><time datetime="<?= e_attr(substr((string) $p['published_at'], 0, 10)) ?>"><?= e(date('j F Y', strtotime((string) $p['published_at'] . ' UTC'))) ?></time></p>
                    <p class="mt-2 text-sm text-slate-600"><?= e($p['excerpt'] ?: BlogFormatter::plainText((string) $p['body_html'], 200)) ?></p>
                    <p class="mt-3"><a class="btn btn-secondary btn-sm" href="/blog/<?= e_attr($p['slug']) ?>">Read article<span class="sr-only">: <?= e($p['title']) ?></span></a></p>
                </li>
            <?php endforeach ?>
        </ul>
        <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/blog', 'query' => []]) ?>
    <?php endif ?>
</section>
<?php $this->stop(); ?>
