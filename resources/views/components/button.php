<?php
/**
 * component('button', [
 *   'label' => 'Save', 'variant' => 'primary'|'secondary'|'danger'|'ghost',
 *   'type' => 'submit'|'button', 'href' => '/path', 'size' => 'sm'|null,
 *   'confirm' => 'Are you sure?', 'icon' => '<svg…>', 'attrs' => 'data-x="y"',
 *   'disabled' => false,
 * ])
 */
$variant = $variant ?? 'primary';
$classes = trim('btn btn-' . $variant . (($size ?? null) === 'sm' ? ' btn-sm' : '') . ' ' . ($class ?? ''));
$confirmAttr = isset($confirm) && $confirm !== '' ? ' data-confirm="' . e_attr($confirm) . '"' : '';
$extra = isset($attrs) ? ' ' . $attrs : '';
$inner = (isset($icon) ? $icon . ' ' : '') . e($label ?? '');
?>
<?php if (!empty($href)): ?>
<a href="<?= e_url($href) ?>" class="<?= e_attr($classes) ?>"<?= $confirmAttr ?><?= $extra ?>><?= $inner ?></a>
<?php else: ?>
<button type="<?= e_attr($type ?? 'button') ?>" class="<?= e_attr($classes) ?>"<?= !empty($disabled) ? ' disabled' : '' ?><?= $confirmAttr ?><?= $extra ?>><?= $inner ?></button>
<?php endif ?>
