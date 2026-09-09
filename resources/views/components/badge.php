<?php
/**
 * Status pill. Colour comes from data, never from colour alone — an optional
 * dot + the text carry the meaning.
 *
 * component('badge', ['label' => 'Open', 'color' => 'green', 'dot' => true])
 * colors: slate gray red amber yellow green emerald blue indigo violet rose
 */
$color = preg_match('/^[a-z]+$/', $color ?? 'slate') ? $color : 'slate';
?>
<span class="badge bg-<?= $color ?>-50 text-<?= $color ?>-700 ring-<?= $color ?>-200">
    <?php if (!empty($dot)): ?><span class="h-1.5 w-1.5 rounded-full bg-<?= $color ?>-500" aria-hidden="true"></span><?php endif ?>
    <?= e($label ?? '') ?>
</span>
