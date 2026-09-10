<?php
/** @var string $method @var string $email */
$this->layout('layouts.guest', ['title' => 'Two-step verification', 'subtitle' => 'Confirm it\'s you to finish signing in.']);
$this->start('content');
?>
<form method="post" action="/two-factor" data-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="mode" id="mode" value="<?= $method === 'email' ? 'email' : 'totp' ?>">

    <div id="prompt-totp" <?= $method === 'email' ? 'hidden' : '' ?>>
        <p class="mb-3 text-sm text-slate-600">Enter the 6-digit code from your authenticator app.</p>
    </div>
    <div id="prompt-email" <?= $method === 'email' ? '' : 'hidden' ?>>
        <p class="mb-3 text-sm text-slate-600">We sent a 6-digit code to <span class="font-medium"><?= e($email) ?></span>.</p>
    </div>
    <div id="prompt-recovery" hidden>
        <p class="mb-3 text-sm text-slate-600">Enter one of your recovery codes.</p>
    </div>

    <?= component('field', [
        'name' => 'code', 'label' => 'Code', 'required' => true,
        'attrs' => 'autocomplete="one-time-code" inputmode="text" autofocus',
    ]) ?>

    <button type="submit" class="btn btn-primary w-full">Verify</button>
</form>

<div class="mt-4 flex flex-col gap-1 text-center text-sm">
    <?php if ($method !== 'email'): ?>
        <button type="button" data-switch="totp" class="text-brand-600 hover:underline" hidden>Use authenticator app</button>
        <button type="button" data-switch="email" class="text-brand-600 hover:underline">Email me a code instead</button>
    <?php endif ?>
    <button type="button" data-switch="recovery" class="text-brand-600 hover:underline">Use a recovery code</button>
    <a href="/login" class="text-slate-500 hover:underline">Cancel</a>
</div>

<form method="post" action="/two-factor/email" id="resend" class="mt-1 text-center text-sm" hidden>
    <?= csrf_field() ?>
    <button type="submit" class="text-brand-600 hover:underline">Send a new code</button>
</form>

<script nonce="<?= e_attr(nonce()) ?>">
(function () {
    var modeInput = document.getElementById('mode');
    var prompts = { totp: 'prompt-totp', email: 'prompt-email', recovery: 'prompt-recovery' };
    var switches = document.querySelectorAll('[data-switch]');
    var resend = document.getElementById('resend');
    function setMode(m) {
        modeInput.value = m;
        Object.keys(prompts).forEach(function (k) {
            var el = document.getElementById(prompts[k]);
            if (el) el.hidden = k !== m;
        });
        switches.forEach(function (b) { b.hidden = b.dataset.switch === m; });
        if (resend) resend.hidden = m !== 'email';
        var email = document.querySelector('[data-switch="email"]');
        if (m === 'recovery' && email) email.hidden = false;
    }
    switches.forEach(function (b) {
        b.addEventListener('click', function () {
            setMode(b.dataset.switch);
            if (b.dataset.switch === 'email' && resend) { resend.querySelector('button').click(); }
        });
    });
    setMode(modeInput.value);
})();
</script>
<?php $this->stop(); ?>
