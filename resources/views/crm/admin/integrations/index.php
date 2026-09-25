<?php
/** @var array<string,string> $groups @var array<string,list<array{key:string,label:string,description:string,state:string}>> $services */
$this->layout('layouts.app', ['title' => 'Integrations', 'currentPath' => '/admin/integrations']);
$this->start('content');

$tone = ['configured' => 'green', 'incomplete' => 'amber', 'off' => 'slate', 'empty' => 'slate'];
?>
<?= component('page-header', [
    'title' => 'Integrations',
    'subtitle' => 'API keys and secrets for every outside service. Secrets are encrypted and can never be read back — only replaced.',
]) ?>

<?php foreach ($groups as $gKey => $gLabel): if (empty($services[$gKey])) { continue; } ?>
    <section class="mb-8" aria-labelledby="g-<?= e_attr($gKey) ?>">
        <h2 id="g-<?= e_attr($gKey) ?>" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500"><?= e($gLabel) ?></h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($services[$gKey] as $s): ?>
                <a href="/admin/integrations/<?= e_attr($s['key']) ?>" class="card card-body block hover:ring-brand-300">
                    <div class="flex items-start justify-between gap-2">
                        <span class="font-medium text-slate-900"><?= e($s['label']) ?></span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500"><?= e($s['description']) ?></p>
                    <p class="mt-3"><?= component('badge', ['label' => $s['state'] === 'configured' ? 'Configured' : ($s['state'] === 'incomplete' ? 'Incomplete' : ($s['state'] === 'off' ? 'Switched off' : 'Not set up')), 'color' => $tone[$s['state']] ?? 'slate', 'dot' => true]) ?></p>
                </a>
            <?php endforeach ?>
        </div>
    </section>
<?php endforeach ?>
<?php $this->stop(); ?>
