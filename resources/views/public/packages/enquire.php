<?php
/** @var array<string,mixed> $pkg @var string $slug */
$this->layout('layouts.public', [
    'title' => 'Enquire — ' . $pkg['name'],
    'description' => 'Send an enquiry about the ' . $pkg['name'] . ' package to ' . $pkg['destination'] . '. Our travel team will contact you.',
    'canonical' => 'travel-packages/' . $slug . '/enquire',
    'robots' => 'noindex, follow',
]);
$this->start('content');
?>
<section class="mx-auto max-w-xl px-4 py-12">
    <p class="text-sm"><a href="/travel-packages/<?= e_attr($slug) ?>" class="text-brand-600 hover:underline">← Back to the package</a></p>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Enquire: <?= e($pkg['name']) ?></h1>
    <p class="mt-1 text-sm text-slate-600"><?= e($pkg['destination']) ?>. Tell us when you would like to travel and how many people — we will call you with availability and the price.</p>

    <?php if (session()?->get('status')): ?>
        <div class="mt-4"><?= component('alert', ['type' => 'success', 'message' => (string) session()->get('status')]) ?></div>
    <?php endif ?>
    <?php if (error('form')): ?>
        <div class="mt-4"><?= component('alert', ['type' => 'danger', 'message' => error('form')]) ?></div>
    <?php endif ?>

    <form method="post" action="/travel-packages/<?= e_attr($slug) ?>/enquire" class="mt-6 card card-body" data-once>
        <?= csrf_field() ?>
        <input type="text" name="company" value="" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
        <?= component('field', ['name' => 'name', 'label' => 'Your name', 'required' => true, 'value' => old('name'), 'autocomplete' => 'name', 'attrs' => 'maxlength="150"']) ?>
        <?= component('field', ['name' => 'phone', 'label' => 'Phone / WhatsApp number', 'type' => 'tel', 'required' => true, 'value' => old('phone'), 'autocomplete' => 'tel', 'attrs' => 'maxlength="30"']) ?>
        <?= component('field', ['name' => 'email', 'label' => 'Email (optional)', 'type' => 'email', 'value' => old('email'), 'autocomplete' => 'email', 'attrs' => 'maxlength="180"']) ?>
        <?= component('field', ['name' => 'message', 'label' => 'Travel dates and number of travellers (optional)', 'control' => 'textarea', 'value' => old('message'), 'rows' => 3, 'attrs' => 'maxlength="1000"']) ?>
        <?= component('turnstile') ?>
        <button type="submit" class="btn btn-primary">Send enquiry</button>
    </form>
</section>
<?php $this->stop(); ?>
