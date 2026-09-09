<?php
/** component('card', ['title' => '', 'actions' => '<a…>', 'body' => '<p>…', 'padded' => true]) */
?>
<div class="card <?= e_attr($class ?? '') ?>">
    <?php if (!empty($title) || !empty($actions)): ?>
        <div class="card-header">
            <h2 class="text-sm font-semibold text-slate-900"><?= e($title ?? '') ?></h2>
            <?php if (!empty($actions)): ?><div class="flex items-center gap-2"><?= $actions ?></div><?php endif ?>
        </div>
    <?php endif ?>
    <div class="<?= ($padded ?? true) ? 'card-body' : '' ?>"><?= $body ?? ($slot ?? '') ?></div>
</div>
