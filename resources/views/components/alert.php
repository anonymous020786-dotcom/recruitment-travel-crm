<?php
/** component('alert', ['type' => 'info'|'success'|'warning'|'danger', 'title' => '', 'message' => '']) */
$type = in_array($type ?? 'info', ['info', 'success', 'warning', 'danger'], true) ? $type : 'info';
$icons = [
    'info'    => 'M12 9v3.75m0 3.75h.007M12 3a9 9 0 100 18 9 9 0 000-18z',
    'success' => 'M9 12.75L11.25 15 15 9.75M12 3a9 9 0 100 18 9 9 0 000-18z',
    'warning' => 'M12 9v3.75m0 3.75h.007M12 4.5l8.5 15h-17l8.5-15z',
    'danger'  => 'M12 9v3.75m0 3.75h.007M12 3a9 9 0 100 18 9 9 0 000-18z',
];
?>
<div class="alert alert-<?= e_attr($type) ?>" role="<?= $type === 'danger' ? 'alert' : 'status' ?>">
    <svg class="h-4 w-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="<?= $icons[$type] ?>" />
    </svg>
    <div>
        <?php if (!empty($title)): ?><p class="font-semibold"><?= e($title) ?></p><?php endif ?>
        <?php if (!empty($message)): ?><p><?= e($message) ?></p><?php endif ?>
        <?php if (!empty($slot)): ?><div><?= $slot ?></div><?php endif ?>
    </div>
</div>
