<?php
/**
 * component('page-header', [
 *   'title' => 'Leads', 'subtitle' => '…',
 *   'breadcrumbs' => [['label' => 'Home', 'href' => '/dashboard'], ['label' => 'Leads']],
 *   'actions' => '<a class="btn btn-primary">New lead</a>',
 * ])
 */
$crumbs = $breadcrumbs ?? [];
?>
<div class="mb-5">
    <?php if ($crumbs): ?>
        <nav class="mb-1.5 text-xs text-slate-500" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1">
                <?php foreach ($crumbs as $i => $c): ?>
                    <li class="flex items-center gap-1">
                        <?php if ($i > 0): ?><span aria-hidden="true">/</span><?php endif ?>
                        <?php if (!empty($c['href']) && $i < count($crumbs) - 1): ?>
                            <a href="<?= e_url($c['href']) ?>"><?= e($c['label']) ?></a>
                        <?php else: ?>
                            <span class="text-slate-700" aria-current="page"><?= e($c['label']) ?></span>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
        </nav>
    <?php endif ?>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-lg font-semibold text-slate-900"><?= e($title ?? '') ?></h1>
            <?php if (!empty($subtitle)): ?><p class="mt-0.5 text-sm text-slate-500"><?= e($subtitle) ?></p><?php endif ?>
        </div>
        <?php if (!empty($actions)): ?><div class="flex flex-wrap items-center gap-2"><?= $actions ?></div><?php endif ?>
    </div>
</div>
