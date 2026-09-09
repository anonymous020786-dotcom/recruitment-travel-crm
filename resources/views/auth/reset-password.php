<?php
/** @var string $token @var string $email */
$this->layout('layouts.guest', ['title' => 'Choose a new password']);
?>
<?php $this->start('content'); ?>
<form method="post" action="/reset-password" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e_attr($token) ?>">

    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e_attr((string) old('email', $email)) ?>"
               autocomplete="username" required>
        <?php if ($m = error('email')): ?><p class="err"><?= e($m) ?></p><?php endif ?>
    </div>

    <div class="field">
        <label for="password">New password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="10">
        <?php if ($m = error('password')): ?><p class="err"><?= e($m) ?></p><?php endif ?>
    </div>

    <div class="field">
        <label for="password_confirmation">Confirm new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation"
               autocomplete="new-password" required minlength="10">
    </div>

    <button type="submit">Reset password</button>
    <div class="row"><span></span><a href="/login">Back to sign in</a></div>
</form>
<?php $this->stop(); ?>
