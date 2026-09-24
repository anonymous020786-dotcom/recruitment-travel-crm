<?php
/**
 * @var \App\Models\Refund $refund @var list<array{from:?string,to:string,reason:?string,by:?string,at:string}> $history
 * @var bool $canApprove @var bool $canReject @var bool $canPay
 */
$this->layout('layouts.app', ['title' => $refund->refundNumber, 'currentPath' => '/refunds']);
$this->start('content');

$color = ['pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'rejected' => 'red'];
$base = '/refunds/' . e_attr($refund->publicId);
$v = '<input type="hidden" name="record_version" value="' . (int) $refund->recordVersion . '">';
?>
<?= component('page-header', [
    'title' => $refund->refundNumber,
    'subtitle' => $refund->customerName . ' · ' . $refund->money(),
    'breadcrumbs' => [['label' => 'Refunds', 'href' => '/refunds'], ['label' => $refund->refundNumber]],
]) ?>

<div class="mb-4"><?= component('badge', ['label' => $refund->statusLabel(), 'color' => $color[$refund->status] ?? 'slate', 'dot' => true]) ?></div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Refund', 'body' => (function () use ($refund) {
            $rows = [
                'Customer' => e($refund->customerName),
                'Amount' => '<span class="font-semibold">' . e($refund->money()) . '</span>',
                'Method' => e($refund->methodLabel()),
                'Payment' => '<a href="/payments/' . e_attr($refund->paymentPublicId) . '" class="font-mono text-brand-600 hover:underline">' . e($refund->paymentNumber) . '</a>',
                'Taken from' => $refund->invoiceNumber !== null
                    ? 'Invoice <a href="/invoices/' . e_attr((string) $refund->invoicePublicId) . '" class="font-mono text-brand-600 hover:underline">' . e($refund->invoiceNumber) . '</a>'
                    : 'Unallocated credit',
                'Requested by' => e($refund->requestedBy ?? '—') . ' · ' . e(substr($refund->createdAt, 0, 16)),
                'Approved by' => $refund->approvedBy ? e($refund->approvedBy) . ' · ' . e(substr((string) $refund->approvedAt, 0, 16)) : '—',
                'Paid out' => e($refund->refundedAt ? substr($refund->refundedAt, 0, 16) : '—'),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $val) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $val . '</dd></div>';
            }

            return $html . '</dl><p class="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-600"><span class="text-slate-500">Reason:</span> ' . e($refund->reason) . '</p>';
        })()]) ?>

        <?= component('card', ['title' => 'Status history', 'body' => (function () use ($history) {
            $html = '<ol class="space-y-3 text-sm">';
            foreach ($history as $h) {
                $html .= '<li class="flex gap-3"><span class="mt-0.5 text-slate-400" aria-hidden="true">•</span><div>'
                    . '<p class="text-slate-800">' . ($h['from'] !== null ? e(ucfirst($h['from'])) . ' → ' : '') . '<strong>' . e(ucfirst($h['to'])) . '</strong></p>'
                    . ($h['reason'] ? '<p class="text-slate-600">' . e($h['reason']) . '</p>' : '')
                    . '<p class="text-xs text-slate-400">' . ($h['by'] ? e($h['by']) . ' · ' : '') . e(substr($h['at'], 0, 16)) . '</p></div></li>';
            }

            return $html . '</ol>';
        })()]) ?>
    </div>

    <div class="space-y-4">
        <?php if ($canApprove): ?>
            <?= component('card', ['title' => 'Approve', 'body' => '<form method="post" action="' . $base . '/approve" data-once data-confirm="Approve this refund?">' . csrf_field() . $v
                . '<button class="btn btn-primary btn-sm">Approve refund</button></form>'
                . '<p class="mt-2 text-xs text-slate-400">The person who requested a refund cannot approve it.</p>']) ?>
        <?php endif ?>
        <?php if ($canPay): ?>
            <?= component('card', ['title' => 'Mark as paid', 'body' => '<form method="post" action="' . $base . '/paid" data-once data-confirm="Confirm the money has been returned to the customer?">' . csrf_field() . $v
                . '<button class="btn btn-primary btn-sm">Mark as paid</button></form>'
                . ($refund->invoiceNumber ? '<p class="mt-2 text-xs text-slate-400">The invoice will owe this amount again.</p>' : '')]) ?>
        <?php endif ?>
        <?php if ($canReject): ?>
            <?= component('card', ['title' => 'Reject', 'body' => '<form method="post" action="' . $base . '/reject" class="space-y-2" data-once>' . csrf_field() . $v
                . '<input type="text" name="reason" required maxlength="255" placeholder="Why is it rejected? (required)" aria-label="Reason" class="form-input w-full">'
                . '<button class="btn btn-ghost btn-sm text-red-600">Reject refund</button></form>']) ?>
        <?php endif ?>
    </div>
</div>
<?php $this->stop(); ?>
