<?php
/** @var \App\Payments\Checkout $checkout */
$this->layout('layouts.public', ['title' => 'Redirecting to the payment page', 'description' => 'Secure online payment.', 'canonical' => 'pay', 'robots' => 'noindex, nofollow']);
$this->start('content');
?>
<section class="mx-auto max-w-md px-4 py-16 text-center">
    <div class="card card-body">
        <h1 class="text-lg font-semibold text-slate-900">Taking you to the secure payment page…</h1>
        <form id="gw" method="post" action="<?= e_attr($checkout->url) ?>" class="mt-4">
            <?php foreach ($checkout->fields as $name => $value): ?><input type="hidden" name="<?= e_attr((string) $name) ?>" value="<?= e_attr((string) $value) ?>"><?php endforeach ?>
            <noscript><p class="mb-3 text-sm text-slate-600">JavaScript is off, so please press the button to continue.</p></noscript>
            <button type="submit" class="btn btn-primary">Continue to payment</button>
        </form>
    </div>
</section>
<script nonce="<?= e_attr(nonce()) ?>">document.getElementById('gw').submit();</script>
<?php $this->stop(); ?>
