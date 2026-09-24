<?php
$this->layout('layouts.public', [
    'title' => 'Contact us',
    'description' => 'Get in touch with ' . (string) config('seo.organization_name', config('app.name')) . ' — call, WhatsApp or send an enquiry about overseas jobs, recruitment and travel packages.',
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
