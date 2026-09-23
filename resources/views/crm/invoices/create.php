<?php
/** @var string $type @var string $reference @var array<string,mixed> $prefill */
$this->layout('layouts.app', ['title' => 'New invoice', 'currentPath' => '/invoices']);
$this->start('content');
$locked = $reference !== '';
?>
<?= component('page-header', [
    'title' => 'New invoice',
    'breadcrumbs' => [['label' => 'Invoices', 'href' => '/invoices'], ['label' => 'New invoice']],
]) ?>

<form method="post" action="/invoices" data-once>
    <?= csrf_field() ?>
    <div class="card card-body">
        <div class="grid gap-x-5 sm:grid-cols-3">
            <?= component('field', [
                'name' => 'type', 'label' => 'Invoice for', 'control' => 'select', 'required' => true,
                'options' => ['application' => 'Recruitment application', 'tour_booking' => 'Tour booking'],
                'value' => (string) old('type', $type),
            ]) ?>
            <?= component('field', [
                'name' => 'reference', 'label' => 'Application / booking number', 'required' => true,
                'value' => (string) old('reference', $reference), 'attrs' => 'maxlength="24" placeholder="APP-2026-000001 or TB-2026-000001"' . ($locked ? '' : ' autofocus'),
            ]) ?>
            <div></div>
        </div>
        <?= $this->partial('crm.invoices._form', ['prefill' => $prefill]) ?>
    </div>
    <p class="mt-2 text-xs text-slate-500">The invoice starts as a draft. Issue it from its page when it is ready; issuing freezes the amounts.</p>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Create draft</button>
        <a href="/invoices" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
