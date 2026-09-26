<?php
/** @var string $active overview|settings|rules|test */
$items = ['overview' => ['/admin/security/firewall', 'Activity'], 'settings' => ['/admin/security/firewall/settings', 'Protection & countries'], 'rules' => ['/admin/security/firewall/rules', 'Custom rules'], 'test' => ['/admin/security/firewall/test', 'Test a request']];
?>
<nav class="mb-5 flex flex-wrap gap-2" aria-label="Firewall sections">
    <?php foreach ($items as $key => [$href, $label]): ?>
        <a href="<?= e_attr($href) ?>" class="btn btn-sm <?= $key === $active ? 'btn-primary' : 'btn-secondary' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
</nav>
