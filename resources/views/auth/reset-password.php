<?php
/** @var string $token @var string $email */
$this->layout('layouts.guest', ['title' => 'Choose a new password', 'subtitle' => 'Pick a strong password you have not used before.']);
$this->start('content');
?>
<form method="post" action="/reset-password" data-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e_attr($token) ?>">

    <?= component('field', [
        'name' => 'email', 'label' => 'Email address', 'type' => 'email',
        'value' => old('email', $email), 'autocomplete' => 'username', 'required' => true,
    ]) ?>

    <?= component('field', [
        'name' => 'password', 'label' => 'New password', 'type' => 'password',
        'autocomplete' => 'new-password', 'required' => true, 'attrs' => 'minlength="10"',
        'hint' => 'At least 10 characters.',
    ]) ?>

    <?= component('field', [
        'name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password',
        'autocomplete' => 'new-password', 'required' => true, 'attrs' => 'minlength="10"',
    ]) ?>

    <button type="submit" class="btn btn-primary w-full">Reset password</button>

    <p class="mt-4 text-center text-sm"><a href="/login">Back to sign in</a></p>
</form>
<?php $this->stop(); ?>
