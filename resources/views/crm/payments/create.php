<?php
/** @var \App\Models\Invoice $invoice @var string $token */
$this->layout('layouts.app', ['title' => 'Record payment', 'currentPath' => '/payments']);
$this->start('content');
$outstanding = $invoice->outstanding();
?>
<?= component('page-header', [
    'title' => 'Record payment',
    'subtitle' => $invoice->invoiceNumber . ' · ' . $invoice->customerName,
    'breadcrumbs' => [
        ['label' => 'Invoices', 'href' => '/invoices'],
        ['label' => $invoice->invoiceNumber, 'href' => '/invoices/' . $invoice->publicId],
        ['label' => 'Record payment'],
    ],
]) ?>

<p class="mb-4 text-sm text-slate-600">Outstanding on this invoice: <span class="font-semibold text-slate-900"><?= e($invoice->money($outstanding)) ?></span>.
    The payment is recorded in <?= e($invoice->currency) ?>. Anything paid above the outstanding amount is kept as credit on the customer.</p>

<form method="post" action="/invoices/<?= e_attr($invoice->publicId) ?>/payments" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="idempotency_key" value="<?= e_attr((string) old('idempotency_key', $token)) ?>">
    <div class="card card-body">
        <div class="grid gap-x-5 sm:grid-cols-3">
            <?= component('field', ['name' => 'amount', 'label' => 'Amount received (' . $invoice->currency . ')', 'type' => 'number', 'required' => true, 'value' => (string) old('amount', $outstanding), 'attrs' => 'min="0.01" step="0.01" autofocus']) ?>
            <?= component('field', [
                'name' => 'method', 'label' => 'Method', 'control' => 'select', 'required' => true,
                'options' => \App\Models\Payment::METHODS, 'value' => (string) old('method', 'cash'),
            ]) ?>
            <?= component('field', ['name' => 'paid_at', 'label' => 'Received on', 'type' => 'datetime-local', 'value' => (string) old('paid_at', gmdate('Y-m-d\TH:i')), 'attrs' => 'max="' . e_attr(gmdate('Y-m-d\TH:i', time() + 14 * 3600)) . '"']) ?>
            <div class="sm:col-span-2">
                <?= component('field', ['name' => 'reference', 'label' => 'Reference', 'value' => (string) old('reference', ''), 'hint' => 'Required for bank transfer, UPI, card and cheque: UTR, transaction id or cheque number.', 'attrs' => 'maxlength="120"']) ?>
            </div>
            <div></div>
            <div class="sm:col-span-3">
                <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'control' => 'textarea', 'rows' => 2, 'value' => (string) old('notes', ''), 'attrs' => 'maxlength="500"']) ?>
            </div>
        </div>
    </div>
    <p class="mt-2 text-xs text-slate-500">A numbered receipt is issued immediately. A payment can be reversed later but never edited or deleted.</p>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Record payment</button>
        <a href="/invoices/<?= e_attr($invoice->publicId) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
