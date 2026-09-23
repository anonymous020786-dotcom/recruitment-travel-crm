<?php
/** @var \App\Models\Invoice $invoice @var list<array<string,string>> $lines */
$this->layout('layouts.app', ['title' => 'Edit ' . $invoice->invoiceNumber, 'currentPath' => '/invoices']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Edit ' . $invoice->invoiceNumber,
    'subtitle' => $invoice->customerName,
    'breadcrumbs' => [
        ['label' => 'Invoices', 'href' => '/invoices'],
        ['label' => $invoice->invoiceNumber, 'href' => '/invoices/' . $invoice->publicId],
        ['label' => 'Edit'],
    ],
]) ?>

<form method="post" action="/invoices/<?= e_attr($invoice->publicId) ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">
    <input type="hidden" name="record_version" value="<?= (int) $invoice->recordVersion ?>">
    <div class="card card-body"><?= $this->partial('crm.invoices._form', compact('invoice', 'lines')) ?></div>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Save draft</button>
        <a href="/invoices/<?= e_attr($invoice->publicId) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
