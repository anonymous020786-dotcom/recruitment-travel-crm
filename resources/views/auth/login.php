<?php $this->layout('layouts.guest', ['title' => 'Sign in']); ?>
<?php $this->start('content'); ?>
<form method="post" action="/login" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e_attr((string) old('email', '')) ?>"
               autocomplete="username" autofocus required>
        <?php if ($m = error('email')): ?><p class="err"><?= e($m) ?></p><?php endif ?>
    </div>

    <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <?php if ($m = error('password')): ?><p class="err"><?= e($m) ?></p><?php endif ?>
    </div>

    <button type="submit">Sign in</button>

    <div class="row">
        <span></span>
        <a href="/forgot-password">Forgot your password?</a>
    </div>
</form>
<?php $this->stop(); ?>
