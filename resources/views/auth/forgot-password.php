<?php $this->layout('layouts.guest', ['title' => 'Reset password']); ?>
<?php $this->start('content'); ?>
<p class="sub" style="margin-top:-1rem">
    Enter your email and we'll send a link to reset your password.
</p>
<form method="post" action="/forgot-password" novalidate>
    <?= csrf_field() ?>
    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e_attr((string) old('email', '')) ?>"
               autocomplete="username" autofocus required>
        <?php if ($m = error('email')): ?><p class="err"><?= e($m) ?></p><?php endif ?>
    </div>
    <button type="submit">Email password reset link</button>
    <div class="row"><span></span><a href="/login">Back to sign in</a></div>
</form>
<?php $this->stop(); ?>
