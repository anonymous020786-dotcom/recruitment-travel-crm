<?php
/** @var array<string,mixed> $page @var string $status @var bool $cancelled */
$this->layout('layouts.public', ['title' => 'Payment status', 'description' => 'Secure online payment.', 'canonical' => 'pay/' . $page['public_id'] . '/return', 'robots' => 'noindex, nofollow']);
$this->start('content');
$money = e($page['currency'] . ' ' . number_format((float) $page['amount'], 2));
?>
<section class="mx-auto max-w-md px-4 py-16">
    <div class="card card-body text-center">
        <p class="text-sm text-slate-500">Invoice <?= e($page['invoice_number']) ?> · <?= $money ?></p>
        <?php if ($status === 'paid'): ?>
            <h1 class="mt-3 text-2xl font-bold text-green-700">Payment received</h1>
            <p class="mt-2 text-sm text-slate-600">Thank you<?= $page['customer'] !== '' ? ', ' . e($page['customer']) : '' ?>. A receipt will be shared with you by our team.</p>
        <?php elseif ($status === 'failed' || $cancelled): ?>
            <h1 class="mt-3 text-2xl font-bold text-slate-900"><?= $cancelled ? 'Payment cancelled' : 'Payment not completed' ?></h1>
            <p class="mt-2 text-sm text-slate-600">Nothing has been charged. You can try again with the same link.</p>
            <p class="mt-4"><a class="btn btn-primary" href="/pay/<?= e_attr($page['public_id']) ?>">Try again</a></p>
        <?php else: ?>
            <h1 class="mt-3 text-2xl font-bold text-slate-900">We are confirming your payment</h1>
            <p class="mt-2 text-sm text-slate-600">This usually takes a few seconds. You do not need to pay again — if it went through, our team will see it automatically. Refresh this page in a moment to check.</p>
            <p class="mt-4"><a class="btn btn-secondary" href="/pay/<?= e_attr($page['public_id']) ?>">Check status</a></p>
        <?php endif ?>
    </div>
</section>
<?php $this->stop(); ?>
