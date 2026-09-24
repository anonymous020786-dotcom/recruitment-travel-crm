<?php
$this->layout('layouts.public', [
    'title' => 'Contact us',
    'description' => 'Get in touch with ' . (string) setting('business.name', config('app.name')) . ' — call, WhatsApp or send an enquiry about overseas jobs, recruitment and travel packages.',
    'canonical' => 'contact',
]);
$this->start('content');
?>
<section class="mx-auto max-w-xl px-4 py-16">
    <h1 class="text-2xl font-bold text-slate-900">Contact us</h1>
    <p class="mt-1 text-sm text-slate-600">Send us a message and our team will get back to you.</p>

    <?php if (session()?->get('status')): ?>
        <div class="mt-4"><?= component('alert', ['type' => 'success', 'message' => (string) session()->get('status')]) ?></div>
    <?php endif ?>
    <?php if (error('form')): ?>
        <div class="mt-4"><?= component('alert', ['type' => 'danger', 'message' => error('form')]) ?></div>
    <?php endif ?>

    <?php
    $phone = (string) setting('business.phone', '');
    $wa = (string) setting('business.whatsapp', '');
    $mail = (string) setting('business.email', '');
    $address = (string) setting('business.address', '');
    $hours = (string) setting('business.hours', '');
    ?>
    <?php if ($phone !== '' || $wa !== '' || $mail !== '' || $address !== '' || $hours !== ''): ?>
        <dl class="mt-6 card card-body grid gap-3 text-sm sm:grid-cols-2">
            <?php if ($phone !== ''): ?><div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Call</dt><dd><a class="text-brand-600 hover:underline" href="tel:<?= e_attr(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a></dd></div><?php endif ?>
            <?php if ($wa !== ''): ?><div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">WhatsApp</dt><dd><a class="text-brand-600 hover:underline" href="https://wa.me/<?= e_attr(preg_replace('/\D+/', '', $wa)) ?>" rel="noopener"><?= e($wa) ?></a></dd></div><?php endif ?>
            <?php if ($mail !== ''): ?><div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Email</dt><dd><a class="text-brand-600 hover:underline" href="mailto:<?= e_attr($mail) ?>"><?= e($mail) ?></a></dd></div><?php endif ?>
            <?php if ($hours !== ''): ?><div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Hours</dt><dd class="text-slate-700"><?= e($hours) ?></dd></div><?php endif ?>
            <?php if ($address !== ''): ?><div class="sm:col-span-2"><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Visit us</dt><dd class="whitespace-pre-line text-slate-700"><?= e($address) ?></dd></div><?php endif ?>
        </dl>
    <?php endif ?>

    <form method="post" action="/contact" class="mt-6 card card-body" data-once>
        <?= csrf_field() ?>
        <input type="text" name="company" value="" tabindex="-1" autocomplete="off"
               class="hidden" aria-hidden="true">

        <?= component('field', ['name' => 'name', 'label' => 'Your name', 'required' => true, 'value' => old('name'), 'attrs' => 'maxlength="150"']) ?>
        <?= component('field', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true, 'value' => old('phone'), 'attrs' => 'maxlength="30"']) ?>
        <?= component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => old('email'), 'attrs' => 'maxlength="180"']) ?>
        <?= component('field', ['name' => 'message', 'label' => 'Message', 'control' => 'textarea', 'required' => true, 'value' => old('message'), 'rows' => 4, 'attrs' => 'maxlength="1000"']) ?>

        <?= component('turnstile') ?>

        <button type="submit" class="btn btn-primary">Send message</button>
    </form>
</section>
<?php $this->stop(); ?>
