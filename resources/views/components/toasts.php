<?php
/**
 * Renders flash toasts from the session (`status`, `error_toast`) plus anything
 * passed in `$toasts` as ['type' => 'success', 'message' => '…'].
 */
$queue = $toasts ?? [];
if ($s = session()?->get('status')) {
    $queue[] = ['type' => 'success', 'message' => (string) $s];
}
if ($e = session()?->get('error_toast')) {
    $queue[] = ['type' => 'danger', 'message' => (string) $e];
}
if ($queue === []) {
    return;
}
?>
<div class="fixed inset-x-0 top-3 z-50 flex flex-col items-center gap-2 px-3 sm:items-end sm:pr-4" aria-live="polite">
    <?php foreach ($queue as $t): ?>
        <?php $type = in_array($t['type'] ?? 'info', ['info', 'success', 'warning', 'danger'], true) ? $t['type'] : 'info'; ?>
        <div data-toast="5000"
             class="alert alert-<?= e_attr($type) ?> w-full max-w-sm shadow-lg transition-opacity duration-200">
            <span class="flex-1"><?= e($t['message'] ?? '') ?></span>
            <button type="button" data-toast-close class="text-current/70 hover:text-current" aria-label="Dismiss">&times;</button>
        </div>
    <?php endforeach ?>
</div>
