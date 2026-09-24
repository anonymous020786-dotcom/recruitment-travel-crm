<?php
/**
 * @var \App\Models\Payment $payment @var list<array{invoice_id:int,invoice_public_id:string,invoice_number:string,amount:string}> $allocations
 * @var bool $canAllocate @var bool $canReverse @var bool $canEdit @var bool $canReceipt
 * @var list<\App\Models\Refund> $refunds @var bool $canRefund @var array<string,string> $refundTargets
 */
$this->layout('layouts.app', ['title' => $payment->paymentNumber, 'currentPath' => '/payments']);
$this->start('content');

$base = '/payments/' . e_attr($payment->publicId);
$m = static fn (string $v): string => e($payment->money($v));
$actions = $canReceipt ? '<a href="' . $base . '/receipt" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Receipt</a>' : '';
?>
<?= component('page-header', [
    'title' => $payment->paymentNumber,
    'subtitle' => $payment->customerName . ' · ' . $payment->money($payment->amount),
    'breadcrumbs' => [['label' => 'Payments', 'href' => '/payments'], ['label' => $payment->paymentNumber]],
    'actions' => $actions,
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $payment->statusLabel(), 'color' => $payment->isRecorded() ? 'green' : 'red', 'dot' => true]) ?>
    <?php if ($payment->unallocatedMinor() > 0): ?><?= component('badge', ['label' => 'Unallocated ' . $payment->money($payment->unallocated()), 'color' => 'amber']) ?><?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Payment', 'body' => (function () use ($payment, $m) {
            $rows = [
                'Customer' => e($payment->customerName) . ($payment->customerPhone ? ' <span class="text-xs text-slate-500">' . e($payment->customerPhone) . '</span>' : ''),
                'Amount' => '<span class="font-semibold">' . $m($payment->amount) . '</span>',
                'Method' => e($payment->methodLabel()),
                'Reference' => $payment->reference ? '<span class="font-mono">' . e($payment->reference) . '</span>' : '—',
                'Received' => e(substr($payment->paidAt, 0, 16)),
                'Recorded by' => e($payment->receivedBy ?? '—'),
                'Receipt' => '<span class="font-mono">' . e($payment->receiptNumber) . '</span>',
                'Applied to invoices' => $m($payment->allocated),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }
            $html .= '</dl>';
            if ($payment->notes) {
                $html .= '<p class="mt-3 whitespace-pre-line border-t border-slate-100 pt-3 text-sm text-slate-600">' . e($payment->notes) . '</p>';
            }
            if (!$payment->isRecorded()) {
                $html .= '<p class="mt-3 border-t border-slate-100 pt-3 text-sm text-red-700">Reversed: ' . e((string) $payment->reversedReason) . '</p>';
            }

            return $html;
        })()]) ?>

        <?= component('card', ['title' => 'Allocations', 'body' => (function () use ($allocations, $payment, $m) {
            if ($allocations === []) {
                return '<p class="text-sm text-slate-500">Not applied to any invoice yet.</p>';
            }
            $html = '<ul class="divide-y divide-slate-100">';
            foreach ($allocations as $a) {
                $html .= '<li class="flex items-center justify-between py-2 text-sm"><a href="/invoices/' . e_attr($a['invoice_public_id']) . '" class="font-mono font-medium text-brand-600 hover:underline">' . e($a['invoice_number']) . '</a>'
                    . '<span class="font-medium ' . ($payment->isRecorded() ? '' : 'text-slate-400 line-through') . '">' . $m($a['amount']) . '</span></li>';
            }

            return $html . '</ul>' . (!$payment->isRecorded() ? '<p class="mt-2 text-xs text-slate-400">Released when the payment was reversed.</p>' : '');
        })()]) ?>
    </div>

    <div class="space-y-4" id="refunds">
        <?php if ($refunds !== []): ?>
            <?= component('card', ['title' => 'Refunds', 'body' => (function () use ($refunds) {
                $color = ['pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'rejected' => 'red'];
                $html = '<ul class="divide-y divide-slate-100">';
                foreach ($refunds as $r) {
                    /** @var \App\Models\Refund $r */
                    $html .= '<li class="flex items-center justify-between gap-2 py-2 text-sm"><div><a href="/refunds/' . e_attr($r->publicId) . '" class="font-mono font-medium text-slate-900">' . e($r->refundNumber) . '</a>'
                        . '<p class="text-xs text-slate-500">' . e($r->money()) . ' · ' . e($r->invoiceNumber ?? 'credit') . '</p></div>'
                        . component('badge', ['label' => $r->statusLabel(), 'color' => $color[$r->status] ?? 'slate']) . '</li>';
                }

                return $html . '</ul>';
            })()]) ?>
        <?php endif ?>

        <?php if ($canRefund): ?>
            <?= component('card', ['title' => 'Request a refund', 'body' => (function () use ($refundTargets, $base, $payment) {
                $opts = '';
                foreach ($refundTargets as $publicId => $label) {
                    $opts .= '<option value="' . e_attr((string) $publicId) . '">' . e($label) . '</option>';
                }
                $methods = '';
                foreach (\App\Models\Refund::METHODS as $k => $lbl) {
                    $methods .= '<option value="' . e_attr($k) . '">' . e($lbl) . '</option>';
                }

                return '<form method="post" action="' . $base . '/refunds" class="space-y-2" data-once>' . csrf_field()
                    . '<label class="text-xs text-slate-500">Take it from<select name="invoice" class="form-select mt-1 w-full">' . $opts . '</select></label>'
                    . '<input type="number" name="amount" required min="0.01" step="0.01" placeholder="Amount (' . e($payment->currency) . ')" aria-label="Amount" class="form-input w-full">'
                    . '<select name="method" aria-label="Refund method" class="form-select w-full">' . $methods . '</select>'
                    . '<input type="text" name="reason" required minlength="3" maxlength="255" placeholder="Why? (required)" aria-label="Reason" class="form-input w-full">'
                    . '<button class="btn btn-secondary btn-sm">Request refund</button></form>'
                    . '<p class="mt-2 text-xs text-slate-400">A refund needs approval by someone else before it can be paid out.</p>';
            })()]) ?>
        <?php endif ?>

        <?php if ($canAllocate): ?>
            <?= component('card', ['title' => 'Allocate credit', 'body' => '<form method="post" action="' . $base . '/allocate" class="space-y-2" data-once>' . csrf_field()
                . '<input type="hidden" name="record_version" value="' . (int) $payment->recordVersion . '">'
                . '<p class="text-sm text-slate-600">' . $m($payment->unallocated()) . ' is not applied to any invoice.</p>'
                . '<input type="text" name="invoice" required maxlength="24" placeholder="Invoice number, e.g. INV-2026-000001" aria-label="Invoice number" class="form-input w-full font-mono">'
                . '<input type="number" name="amount" required min="0.01" step="0.01" value="' . e_attr($payment->unallocated()) . '" aria-label="Amount" class="form-input w-full">'
                . '<button class="btn btn-secondary btn-sm">Allocate</button></form>'
                . '<p class="mt-2 text-xs text-slate-400">Same customer and currency only, up to what the invoice still owes.</p>']) ?>
        <?php endif ?>

        <?php if ($canEdit): ?>
            <?= component('card', ['title' => 'Details', 'body' => '<form method="post" action="' . $base . '" class="space-y-2" data-once>' . csrf_field()
                . '<input type="hidden" name="_method" value="PUT"><input type="hidden" name="record_version" value="' . (int) $payment->recordVersion . '">'
                . '<input type="text" name="reference" maxlength="120" value="' . e_attr($payment->reference ?? '') . '" placeholder="Reference" aria-label="Reference" class="form-input w-full">'
                . '<textarea name="notes" rows="2" maxlength="500" placeholder="Notes" aria-label="Notes" class="form-input w-full">' . e($payment->notes ?? '') . '</textarea>'
                . '<button class="btn btn-secondary btn-sm">Save</button></form>'
                . '<p class="mt-2 text-xs text-slate-400">The amount, method and date are part of the ledger and cannot change.</p>']) ?>
        <?php endif ?>

        <?php if ($canReverse): ?>
            <?= component('card', ['title' => 'Reverse', 'body' => '<form method="post" action="' . $base . '/reverse" class="space-y-2" data-once data-confirm="Reverse this payment? The invoices it settled will owe the money again.">' . csrf_field()
                . '<input type="hidden" name="record_version" value="' . (int) $payment->recordVersion . '">'
                . '<input type="text" name="reason" required maxlength="255" placeholder="Why is it being reversed? (required)" aria-label="Reason" class="form-input w-full">'
                . '<button class="btn btn-ghost btn-sm text-red-600">Reverse payment</button></form>']) ?>
        <?php endif ?>
    </div>
</div>
<?php $this->stop(); ?>
