<?php
/** @var array<string,mixed> $job @var string $slug */
$this->layout('layouts.public', [
    'title' => 'Apply — ' . $job['title'],
    'description' => 'Apply for the ' . $job['title'] . ' vacancy in ' . $job['country_name'] . '. Send your details and our team will contact you.',
    'canonical' => 'overseas-jobs/' . $slug . '/apply',
    'robots' => 'noindex, follow',
]);
$this->start('content');
?>
<section class="mx-auto max-w-xl px-4 py-12">
    <p class="text-sm"><a href="/overseas-jobs/<?= e_attr($slug) ?>" class="text-brand-600">← Back to the job</a></p>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Apply: <?= e($job['title']) ?></h1>
    <p class="mt-1 text-sm text-slate-600"><?= e($job['country_name']) ?><?= !empty($job['city']) ? ' · ' . e($job['city']) : '' ?>. We will call you to take your application forward. Applying is free.</p>

    <?php if (session()?->get('status')): ?>
        <div class="mt-4"><?= component('alert', ['type' => 'success', 'message' => (string) session()->get('status')]) ?></div>
    <?php endif ?>
    <?php if (error('form')): ?>
        <div class="mt-4"><?= component('alert', ['type' => 'danger', 'message' => error('form')]) ?></div>
    <?php endif ?>

    <form method="post" action="/overseas-jobs/<?= e_attr($slug) ?>/apply" class="mt-6 card card-body" data-once>
        <?= csrf_field() ?>
        <input type="text" name="company" value="" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
        <?= component('field', ['name' => 'name', 'label' => 'Your full name', 'required' => true, 'value' => old('name'), 'autocomplete' => 'name', 'attrs' => 'maxlength="150"']) ?>
        <?= component('field', ['name' => 'phone', 'label' => 'Phone / WhatsApp number', 'type' => 'tel', 'required' => true, 'value' => old('phone'), 'autocomplete' => 'tel', 'attrs' => 'maxlength="30"']) ?>
        <?= component('field', ['name' => 'email', 'label' => 'Email (optional)', 'type' => 'email', 'value' => old('email'), 'autocomplete' => 'email', 'attrs' => 'maxlength="180"']) ?>
        <?= component('field', ['name' => 'message', 'label' => 'Anything we should know? (optional)', 'control' => 'textarea', 'value' => old('message'), 'rows' => 3, 'hint' => 'For example your experience, current location, or when you can join.', 'attrs' => 'maxlength="1000"']) ?>
        <?= component('turnstile') ?>
        <button type="submit" class="btn btn-primary">Send my application</button>
    </form>
</section>
<?php $this->stop(); ?>
