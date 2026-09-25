<?php
/**
 * @var \App\Models\Invoice $invoice @var list<array<string,string>> $lines
 * @var list<array{from:?string,to:string,reason:?string,by:?string,at:string}> $history
 * @var bool $canEdit @var bool $canIssue @var bool $canVoid
 */
$this->layout('layouts.app', ['title' => $invoice->invoiceNumber, 'currentPath' => '/invoices']);
$this->start('content');

$color = ['draft' => 'slate', 'issued' => 'blue', 'partially_paid' => 'amber', 'paid' => 'green', 'void' => 'red'];
$base = '/invoices/' . e_attr($invoice->publicId);
$label = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$m = static fn (string $v) => e($invoice->money($v));

$refLink = $invoice->referenceNumber !== null
    ? '<a href="' . ($invoice->type === 'application' ? '/applications/' : '/tours/bookings/') . e_attr((string) $invoice->referencePublicId) . '" class="text-brand-600">' . e($invoice->referenceNumber) . '</a>'
    : '—';
?>
<?= component('page-header', [
    'title' => $invoice->invoiceNumber,
    'subtitle' => $invoice->customerName . ' · ' . $invoice->typeLabel(),
    'breadcrumbs' => [['label' => 'Invoices', 'href' => '/invoices'], ['label' => $invoice->invoiceNumber]],
    'actions' => $canEdit ? '<a href="' . $base . '/edit" class="btn btn-primary btn-sm">Edit</a>' : '',
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $invoice->statusLabel(), 'color' => $color[$invoice->status] ?? 'slate', 'dot' => true]) ?>
    <?php if ($invoice->isOverdue()): ?><?= component('badge', ['label' => 'Overdue', 'color' => 'red', 'dot' => true]) ?><?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Lines', 'body' => (function () use ($lines, $invoice, $m) {
            if ($lines === []) {
                return '<p class="text-sm text-slate-500">No lines yet.</p>';
            }
            $html = '<div class="table-wrap"><table class="data"><thead><tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Total</th></tr></thead><tbody>';
            foreach ($lines as $l) {
                $html .= '<tr><td>' . e($l['description']) . '</td><td class="text-right">' . e(rtrim(rtrim($l['quantity'], '0'), '.')) . '</td>'
                    . '<td class="whitespace-nowrap text-right">' . $m($l['unit_price']) . '</td><td class="whitespace-nowrap text-right font-medium">' . $m($l['line_total']) . '</td></tr>';
            }
            $html .= '</tbody></table></div><dl class="mt-3 ml-auto max-w-xs space-y-1 text-sm">'
                . '<div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd>' . $m($invoice->subtotal) . '</dd></div>'
                . ((float) $invoice->discountTotal > 0 ? '<div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd>− ' . $m($invoice->discountTotal) . '</dd></div>' : '')
                . ((float) $invoice->taxTotal > 0 ? '<div class="flex justify-between"><dt class="text-slate-500">Tax</dt><dd>' . $m($invoice->taxTotal) . '</dd></div>' : '')
                . '<div class="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold"><dt>Total</dt><dd>' . $m($invoice->grandTotal) . '</dd></div></dl>';

            return $html;
        })()]) ?>

        <?php if ($invoice->notes): ?>
            <?= component('card', ['title' => 'Notes', 'body' => '<p class="whitespace-pre-line text-sm text-slate-600">' . e($invoice->notes) . '</p>']) ?>
        <?php endif ?>

        <?php if ($payments !== null && ($payments !== [] || $canPay)): ?>
            <div id="payments">
                <?= component('card', ['title' => 'Payments', 'body' => (function () use ($payments, $canPay, $base, $invoice) {
                    $html = $canPay ? '<p class="mb-3"><a href="' . $base . '/payments/create" class="btn btn-primary btn-sm">Record payment</a></p>' : '';
                    if ($payments === []) {
                        return $html . '<p class="text-sm text-slate-500">No payments yet.</p>';
                    }
                    $html .= '<ul class="divide-y divide-slate-100">';
                    foreach ($payments as $p) {
                        $reversed = $p['status'] === 'reversed';
                        $html .= '<li class="flex items-center justify-between gap-2 py-2 text-sm"><div>'
                            . '<a href="/payments/' . e_attr((string) $p['payment_public_id']) . '" class="font-mono font-medium text-slate-900">' . e((string) $p['payment_number']) . '</a>'
                            . '<p class="text-xs text-slate-500">' . e(ucwords(str_replace('_', ' ', (string) $p['method']))) . ' · ' . e(substr((string) $p['paid_at'], 0, 10)) . '</p></div>'
                            . '<span class="' . ($reversed ? 'text-slate-400 line-through' : 'font-medium text-slate-800') . '">' . e($invoice->money((string) $p['amount'])) . '</span>'
                            . ($reversed ? component('badge', ['label' => 'Reversed', 'color' => 'red']) : '') . '</li>';
                    }

                    return $html . '</ul>';
                })()]) ?>
            </div>
        <?php endif ?>

        <?php if (!empty($online) && ($online['links'] !== [] || $online['can_create'])): ?>
            <?= $this->partial('crm.invoices._online', ['invoice' => $invoice, 'online' => $online]) ?>
        <?php endif ?>

        <div id="history">
            <?= component('card', ['title' => 'Status history', 'body' => (function () use ($history, $label) {
                $html = '<ol class="space-y-3 text-sm">';
                foreach ($history as $h) {
                    $html .= '<li class="flex gap-3"><span class="mt-0.5 text-slate-400" aria-hidden="true">•</span><div>'
                        . '<p class="text-slate-800">' . ($h['from'] !== null ? e($label($h['from'])) . ' → ' : '') . '<strong>' . e($label($h['to'])) . '</strong></p>'
                        . ($h['reason'] ? '<p class="text-slate-600">' . e($h['reason']) . '</p>' : '')
                        . '<p class="text-xs text-slate-400">' . ($h['by'] ? e($h['by']) . ' · ' : '') . e(substr($h['at'], 0, 16)) . '</p></div></li>';
                }

                return $html . '</ol>';
            })()]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Summary', 'body' => (function () use ($invoice, $refLink, $m) {
            $rows = [
                'Customer' => e($invoice->customerName) . ($invoice->customerPhone ? '<br><span class="text-xs text-slate-500">' . e($invoice->customerPhone) . '</span>' : ''),
                'For' => e($invoice->typeLabel()) . ' · ' . $refLink,
                'Issued' => e($invoice->issuedOn ?? '—'),
                'Due' => '<span class="' . ($invoice->isOverdue() ? 'font-medium text-red-600' : '') . '">' . e($invoice->dueOn ?? '—') . '</span>',
                'Paid' => $m($invoice->amountPaid),
                'Refunded' => $m($invoice->amountRefunded),
                'Outstanding' => '<span class="font-semibold">' . ($invoice->status === 'draft' || $invoice->status === 'void' ? '—' : $m($invoice->outstanding())) . '</span>',
            ];
            $html = '<dl class="space-y-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }

            return $html . '</dl>';
        })()]) ?>

        <?php if ($canIssue): ?>
            <?= component('card', ['title' => 'Issue', 'body' => '<form method="post" action="' . $base . '/issue" class="space-y-2" data-once data-confirm="Issue this invoice? Its amounts can no longer be edited.">' . csrf_field()
                . '<input type="hidden" name="record_version" value="' . (int) $invoice->recordVersion . '">'
                . '<label class="text-xs text-slate-500">Due date<input type="date" name="due_on" value="' . e_attr($invoice->dueOn ?? '') . '" min="' . e_attr(gmdate('Y-m-d')) . '" class="form-input mt-1 w-full"></label>'
                . '<p class="text-xs text-slate-400">Blank = ' . (int) \App\Services\InvoiceService::DEFAULT_TERMS_DAYS . ' days from today.</p>'
                . '<button class="btn btn-primary btn-sm">Issue invoice</button></form>']) ?>
        <?php endif ?>

        <?php if ($canVoid): ?>
            <?= component('card', ['title' => 'Void', 'body' => '<form method="post" action="' . $base . '/void" class="space-y-2" data-once data-confirm="Void this invoice? This cannot be undone.">' . csrf_field()
                . '<input type="hidden" name="record_version" value="' . (int) $invoice->recordVersion . '">'
                . '<input type="text" name="reason" required maxlength="255" placeholder="Why is it being voided? (required)" aria-label="Reason" class="form-input w-full">'
                . '<button class="btn btn-ghost btn-sm text-red-600">Void invoice</button></form>'
                . '<p class="mt-2 text-xs text-slate-400">An invoice that has received payments cannot be voided; reverse the payments first.</p>']) ?>
        <?php endif ?>
    </div>
</div>
<?php $this->stop(); ?>
