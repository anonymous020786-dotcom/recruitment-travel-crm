<?php
/**
 * Renders one match's criterion-by-criterion explanation.
 * @var \App\Domain\Matching\MatchResult $result
 */
$icon = ['matched' => '✅', 'partial' => '◐', 'missing' => '❌', 'na' => '➖'];
$color = ['matched' => 'text-green-700', 'partial' => 'text-amber-700', 'missing' => 'text-red-700', 'na' => 'text-slate-400'];
?>
<ul class="mt-2 space-y-1 text-xs">
    <?php foreach ($result->criteria as $c): ?>
        <li class="flex gap-2">
            <span aria-hidden="true"><?= $icon[$c['state']] ?></span>
            <span class="<?= $color[$c['state']] ?>">
                <span class="font-medium"><?= e($c['label']) ?></span>
                <span class="text-slate-400">(weight <?= (int) $c['weight'] ?><?= $c['ratio'] !== null ? ' · ' . (int) round($c['ratio'] * 100) . '%' : '' ?>)</span>
                — <?= e($c['detail']) ?>
            </span>
        </li>
    <?php endforeach ?>
</ul>
