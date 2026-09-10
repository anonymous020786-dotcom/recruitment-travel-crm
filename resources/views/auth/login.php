<?php
$this->layout('layouts.guest', ['title' => 'Sign in', 'subtitle' => 'Sign in to your account to continue.']);
$this->start('content');
?>
<form method="post" action="/login" data-once novalidate>
    <?= csrf_field() ?>

    <?= component('field', [
        'name' => 'email', 'label' => 'Email address', 'type' => 'email',
        'autocomplete' => 'username', 'required' => true, 'attrs' => 'autofocus',
    ]) ?>

    <?= component('field', [
        'name' => 'password', 'label' => 'Password', 'type' => 'password',
        'autocomplete' => 'current-password', 'required' => true,
    ]) ?>

    <label class="mb-2 flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" name="remember" value="1"> Keep me signed in
    </label>
    <label class="mb-4 flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" name="trust_device" value="1"> Trust this device
    </label>

    <button type="submit" class="btn btn-primary w-full">Sign in</button>

    <p class="mt-4 text-center text-sm">
        <a href="/forgot-password">Forgot your password?</a>
    </p>
</form>
<?php $this->stop(); ?>
