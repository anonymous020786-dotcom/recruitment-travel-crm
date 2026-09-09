<?php
$this->layout('layouts.guest', [
    'title' => 'Reset password',
    'subtitle' => "Enter your email and we'll send a link to reset your password.",
]);
$this->start('content');
?>
<form method="post" action="/forgot-password" data-once novalidate>
    <?= csrf_field() ?>

    <?= component('field', [
        'name' => 'email', 'label' => 'Email address', 'type' => 'email',
        'autocomplete' => 'username', 'required' => true, 'attrs' => 'autofocus',
    ]) ?>

    <button type="submit" class="btn btn-primary w-full">Email password reset link</button>

    <p class="mt-4 text-center text-sm"><a href="/login">Back to sign in</a></p>
</form>
<?php $this->stop(); ?>
