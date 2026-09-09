<?php
/** component('stat', ['label' => 'Active candidates', 'value' => 128, 'hint' => '+12 this week', 'href' => '/candidates']) */
$tag = !empty($href) ? 'a' : 'div';
?>
<<?= $tag ?> <?= !empty($href) ? 'href="' . e_url($href) . '"' : '' ?>
    class="card card-body block no-underline hover:ring-brand-200">
    <p class="text-xs font-medium uppercase tracking-wide text-slate-500"><?= e($label ?? '') ?></p>
    <p class="mt-1 text-2xl font-semibold text-slate-900"><?= e((string) ($value ?? '0')) ?></p>
    <?php if (!empty($hint)): ?><p class="mt-0.5 text-xs text-slate-500"><?= e($hint) ?></p><?php endif ?>
</<?= $tag ?>>
