<?php
/** @var string $active overview|rate-limits|policy|ip-rules|sessions */
$tabs = ['overview' => ['/admin/security', 'Overview'], 'rate-limits' => ['/admin/security/rate-limits', 'Rate limits'], 'policy' => ['/admin/security/policy', 'Two-factor & auto-block'], 'ip-rules' => ['/admin/security/ip-rules', 'IP rules'], 'sessions' => ['/admin/security/sessions', 'Sessions']];
?>
<nav class="mb-5 flex flex-wrap gap-1 border-b border-slate-200" aria-label="Security sections">
    <?php foreach ($tabs as $key => [$href, $label]): ?>
        <a href="<?= e_attr($href) ?>" class="-mb-px border-b-2 px-3 py-2 text-sm font-medium <?= $key === $active ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-600' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
</nav>
