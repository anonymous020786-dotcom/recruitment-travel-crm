<?php
/**
 * component('pagination', ['page' => 2, 'perPage' => 25, 'total' => 340, 'baseUrl' => '/leads', 'query' => ['q' => 'x']])
 */
$page = max(1, (int) ($page ?? 1));
$perPage = max(1, (int) ($perPage ?? 25));
$total = max(0, (int) ($total ?? 0));
$pages = (int) max(1, ceil($total / $perPage));
$base = $baseUrl ?? '';
$q = $query ?? [];

$link = static function (int $p) use ($base, $q): string {
    $params = array_merge($q, ['page' => $p]);
    return e_url($base . '?' . http_build_query($params));
};
$from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$to = min($total, $page * $perPage);
?>
<nav class="flex items-center justify-between border-t border-slate-200 px-1 py-3 text-sm" aria-label="Pagination">
    <p class="text-slate-500">
        <?php if ($total === 0): ?>No results<?php else: ?>Showing <span class="font-medium text-slate-700"><?= $from ?></span>–<span class="font-medium text-slate-700"><?= $to ?></span> of <span class="font-medium text-slate-700"><?= $total ?></span><?php endif ?>
    </p>
    <?php if ($pages > 1): ?>
        <div class="flex items-center gap-1">
            <?php if ($page > 1): ?>
                <a class="btn btn-secondary btn-sm" href="<?= $link($page - 1) ?>" rel="prev">Previous</a>
            <?php else: ?>
                <span class="btn btn-secondary btn-sm opacity-50 pointer-events-none">Previous</span>
            <?php endif ?>
            <span class="px-2 text-slate-500">Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-secondary btn-sm" href="<?= $link($page + 1) ?>" rel="next">Next</a>
            <?php else: ?>
                <span class="btn btn-secondary btn-sm opacity-50 pointer-events-none">Next</span>
            <?php endif ?>
        </div>
    <?php endif ?>
</nav>
