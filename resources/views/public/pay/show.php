<?php
/** @var array{public_id:string,status:string,payable:bool,expired:bool,amount:string,currency:string,invoice_number:string,customer:string,description:string,gateway:string,gateway_label:string} $page @var ?string $problem */
$this->layout('layouts.public', ['title' => 'Pay invoice ' . $page['invoice_number'], 'description' => 'Secure online payment.', 'canonical' => 'pay/' . $page['public_id'], 'robots' => 'noindex, nofollow']);
$this->start('content');

$money = e($page['currency'] . ' ' . number_format((float) $page['amount'], 2));
?>
<section class="mx-auto max-w-md px-4 py-16">
    <div class="card card-body text-center">
        <p class="text-sm text-slate-500"><?= e($page['description']) ?></p>
        <h1 class="mt-2 text-3xl font-bold text-slate-900"><?= $money ?></h1>
        <?php if ($page['customer'] !== ''): ?><p class="mt-1 text-sm text-slate-600">For <?= e($page['customer']) ?></p><?php endif ?>

        <?php if (!empty($problem)): ?><div class="mt-4"><?= component('alert', ['type' => 'danger', 'message' => $problem]) ?></div><?php endif ?>

        <?php if ($page['status'] === 'paid'): ?>
            <div class="mt-6"><?= component('alert', ['type' => 'success', 'message' => 'This payment has been received. Thank you!']) ?></div>
        <?php elseif ($page['payable']): ?>
            <form method="post" action="/pay/<?= e_attr($page['public_id']) ?>/go" class="mt-6" data-once><button type="submit" class="btn btn-primary w-full">Pay <?= $money ?> securely</button></form>
            <p class="mt-3 text-xs text-slate-500">You will be taken to <?= e($page['gateway_label']) ?> to complete the payment. We never see or store your card or bank details.</p>
        <?php elseif ($page['expired']): ?>
            <div class="mt-6"><?= component('alert', ['type' => 'warning', 'message' => 'This payment link has expired. Please ask us for a new one.']) ?></div>
        <?php else: ?>
            <div class="mt-6"><?= component('alert', ['type' => 'warning', 'message' => $page['status'] === 'cancelled' ? 'This payment link was cancelled. Please contact us if you still need to pay.' : 'This payment link cannot be used any more. Please contact us.']) ?></div>
        <?php endif ?>
    </div>
</section>
<?php $this->stop(); ?>
