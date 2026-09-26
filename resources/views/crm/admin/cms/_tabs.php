<?php
/** @var string $active pages|redirects|snippets|menus */
$tabs = ['pages' => ['/admin/cms', 'Pages'], 'redirects' => ['/admin/cms/redirects', 'Redirects'], 'snippets' => ['/admin/cms/snippets', 'Snippets'], 'menus' => ['/admin/cms/menus', 'Menus']];
?>
<nav class="mb-5 flex flex-wrap gap-1 border-b border-slate-200" aria-label="Website sections">
    <?php foreach ($tabs as $key => [$href, $label]): ?>
        <a href="<?= e_attr($href) ?>" class="-mb-px border-b-2 px-3 py-2 text-sm font-medium <?= $key === $active ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-600' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
</nav>
