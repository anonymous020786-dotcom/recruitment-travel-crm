<?php
/** @var string $method @var string $email */
$this->layout('layouts.guest', ['title' => 'Two-step verification', 'subtitle' => 'Confirm it\'s you to finish signing in.']);
$this->start('content');

$isPasskey = $method === 'passkey';
$initialMode = $method === 'email' ? 'email' : ($isPasskey ? 'recovery' : 'totp');
?>
<?php if ($isPasskey): ?>
<div data-passkey-only hidden class="mb-4">
    <p class="mb-3 text-sm text-slate-600">Use your passkey — a security key, your phone, or this device — to finish signing in.</p>
    <p id="passkey-2fa-status" hidden
       class="mb-2 text-sm data-[state=error]:text-red-600 data-[state=ok]:text-emerald-600"></p>
    <button type="button" class="btn btn-primary w-full"
            data-passkey-2fa
            data-passkey-status="passkey-2fa-status"
            data-options-url="/two-factor/passkey/options"
            data-verify-url="/two-factor/passkey">
        Verify with a passkey
    </button>
</div>
<noscript><p class="mb-4 text-sm text-red-600">JavaScript is required to verify with a passkey.</p></noscript>
<?php endif ?>

<form method="post" action="/two-factor" id="code-form" data-once novalidate <?= $isPasskey ? 'hidden' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="mode" id="mode" value="<?= e_attr($initialMode) ?>">

    <div id="prompt-totp" <?= $method === 'totp' ? '' : 'hidden' ?>>
        <p class="mb-3 text-sm text-slate-600">Enter the 6-digit code from your authenticator app.</p>
    </div>
    <div id="prompt-email" <?= $method === 'email' ? '' : 'hidden' ?>>
        <p class="mb-3 text-sm text-slate-600">We sent a 6-digit code to <span class="font-medium"><?= e($email) ?></span>.</p>
    </div>
    <div id="prompt-recovery" <?= $isPasskey ? '' : 'hidden' ?>>
        <p class="mb-3 text-sm text-slate-600">Enter one of your recovery codes.</p>
    </div>

    <?= component('field', [
        'name' => 'code', 'label' => 'Code', 'required' => true,
        'attrs' => 'autocomplete="one-time-code" inputmode="text"',
    ]) ?>

    <button type="submit" class="btn btn-primary w-full">Verify</button>
</form>

<div class="mt-4 flex flex-col gap-1 text-center text-sm">
    <?php if ($isPasskey): ?>
        <button type="button" data-reveal-fallback class="text-brand-600">Can't use your passkey?</button>
    <?php endif ?>
    <?php if ($method !== 'email'): ?>
        <button type="button" data-switch="totp" class="text-brand-600" hidden>Use authenticator app</button>
        <button type="button" data-switch="email" class="text-brand-600" <?= $isPasskey ? 'hidden' : '' ?>>Email me a code instead</button>
    <?php endif ?>
    <?php if (!$isPasskey): ?>
        <button type="button" data-switch="recovery" class="text-brand-600">Use a recovery code</button>
    <?php endif ?>
    <a href="/login" class="text-slate-500">Cancel</a>
</div>

<form method="post" action="/two-factor/email" id="resend" class="mt-1 text-center text-sm" hidden>
    <?= csrf_field() ?>
    <button type="submit" class="text-brand-600">Send a new code</button>
</form>

<script nonce="<?= e_attr(nonce()) ?>">
(function () {
    var modeInput = document.getElementById('mode');
    var codeForm = document.getElementById('code-form');
    var prompts = { totp: 'prompt-totp', email: 'prompt-email', recovery: 'prompt-recovery' };
    var switches = document.querySelectorAll('[data-switch]');
    var resend = document.getElementById('resend');
    var reveal = document.querySelector('[data-reveal-fallback]');

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
    if (reveal) {
        reveal.addEventListener('click', function () {
            reveal.hidden = true;
            if (codeForm) codeForm.hidden = false;
            var email = document.querySelector('[data-switch="email"]');
            if (email) email.hidden = false;
        });
    }
    setMode(modeInput.value);
})();
</script>
<?php $this->stop(); ?>
