<?php
/**
 * A native <dialog> modal. Open with a button: data-modal-open="<id>".
 * component('modal', ['id' => 'confirm-delete', 'title' => 'Delete lead?', 'body' => '<p>…', 'footer' => '<button …>'])
 */
$id = $id ?? 'modal';
?>
<dialog id="<?= e_attr($id) ?>" data-modal
        class="w-full max-w-md rounded-xl p-0 backdrop:bg-slate-900/40 open:animate-[fade_.15s_ease]">
    <form method="dialog" class="contents">
        <div class="card-header">
            <h2 class="text-sm font-semibold text-slate-900"><?= e($title ?? '') ?></h2>
            <button type="button" data-modal-close class="btn btn-ghost btn-sm" aria-label="Close">&times;</button>
        </div>
        <div class="card-body text-sm text-slate-600"><?= $body ?? ($slot ?? '') ?></div>
        <div class="card-header border-t border-b-0 justify-end">
            <?= $footer ?? '<button type="button" data-modal-close class="btn btn-secondary">Close</button>' ?>
        </div>
    </form>
</dialog>
