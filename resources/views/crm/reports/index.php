<?php
/** @var array<string,list<array{key:string,title:string,description:string}>> $catalog */
$this->layout('layouts.app', ['title' => 'Reports', 'currentPath' => '/reports']);
$this->start('content');
?>
<?= component('page-header', ['title' => 'Reports', 'subtitle' => 'Filter, print or download as CSV. Figures cover the branches you can see.']) ?>

<?php if ($catalog === []): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No reports available', 'message' => 'Your role does not include any reports.'])]) ?>
<?php else: ?>
    <div class="space-y-6">
        <?php foreach ($catalog as $group => $reports): ?>
            <section>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500"><?= e($group) ?></h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($reports as $r): ?>
                        <a href="/reports/<?= e_attr($r['key']) ?>" class="card card-body block no-underline hover:ring-brand-200">
                            <p class="font-medium text-slate-900"><?= e($r['title']) ?></p>
                            <p class="mt-1 text-sm text-slate-500"><?= e($r['description']) ?></p>
                        </a>
                    <?php endforeach ?>
                </div>
            </section>
        <?php endforeach ?>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
