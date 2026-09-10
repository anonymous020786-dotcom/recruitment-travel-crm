<?php
/**
 * @var list<array<string,mixed>> $passkeys
 * @var bool $isSecondFactor
 * @var string $twoFactorMethod
 */
$this->layout('layouts.app', ['title' => 'Passkeys', 'currentPath' => '/account/security']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Passkeys',
    'breadcrumbs' => [['label' => 'Account'], ['label' => 'Security'], ['label' => 'Passkeys']],
]) ?>

<div class="grid gap-4 lg:grid-cols-2">

    <?= component('card', ['title' => 'Add a passkey', 'body' =>
        '<p class="text-sm text-slate-600">A passkey lets you sign in with your device\'s screen lock (Face ID, fingerprint, PIN) '
        . 'or a hardware security key. It can\'t be phished or reused across sites.</p>'
        . '<div data-passkey-only hidden class="mt-3">'
        . '<label class="block text-sm font-medium text-slate-700">Name <span class="text-slate-400">(so you can tell it apart later)</span></label>'
        . '<input id="passkey-label" type="text" class="form-input mt-1" maxlength="60" placeholder="e.g. Work laptop, YubiKey">'
        . '<p id="passkey-add-status" hidden class="mt-2 text-sm data-[state=error]:text-red-600 data-[state=ok]:text-emerald-600"></p>'
        . '<button type="button" class="btn btn-primary btn-sm mt-3"'
        . ' data-passkey-register'
        . ' data-passkey-status="passkey-add-status"'
        . ' data-passkey-label-from="passkey-label"'
        . ' data-options-url="/account/passkeys/options"'
        . ' data-verify-url="/account/passkeys">Add passkey</button>'
        . '</div>'
        . '<p data-passkey-unsupported class="mt-3 text-sm text-amber-600" hidden>This browser does not support passkeys.</p>'
        . '<noscript><p class="mt-3 text-sm text-red-600">JavaScript is required to add a passkey.</p></noscript>',
    ]) ?>

    <?= component('card', ['title' => 'Use passkeys as your second factor', 'body' => (function () use ($passkeys, $isSecondFactor, $twoFactorMethod) {
        if ($passkeys === []) {
            return '<p class="text-sm text-slate-500">Add at least one passkey first.</p>';
        }
        if ($isSecondFactor) {
            return '<p class="text-sm">' . component('badge', ['label' => 'On', 'color' => 'emerald', 'dot' => true])
                . ' <span class="text-slate-500">A passkey is required after your password at sign-in.</span></p>'
                . '<form method="post" action="/account/passkeys/second-factor" class="mt-3" data-confirm="Stop requiring a passkey at sign-in?">'
                . csrf_field() . '<input type="hidden" name="enable" value="0">'
                . '<button class="btn btn-danger btn-sm">Turn off</button></form>';
        }
        $warn = $twoFactorMethod === 'totp'
            ? '<p class="mt-1 text-xs text-amber-600">This will replace your authenticator app as the second factor.</p>'
            : '';
        return '<p class="text-sm text-slate-600">Require one of your passkeys as the second step after your password.</p>' . $warn
            . '<form method="post" action="/account/passkeys/second-factor" class="mt-3">'
            . csrf_field() . '<input type="hidden" name="enable" value="1">'
            . '<button class="btn btn-primary btn-sm">Turn on</button></form>';
    })()]) ?>

    <div class="lg:col-span-2">
    <?= component('card', ['title' => 'Your passkeys', 'body' => (function () use ($passkeys) {
        if ($passkeys === []) {
            return '<p class="text-sm text-slate-500">You have no passkeys yet.</p>';
        }
        $rows = '';
        foreach ($passkeys as $p) {
            $used = $p['last_used_at'] ? 'Last used ' . e(substr((string) $p['last_used_at'], 0, 10)) : 'Never used';
            $rows .= '<li class="flex items-center justify-between gap-3 py-2 text-sm">'
                . '<span><span class="font-medium">' . e((string) ($p['label'] ?: 'Security key')) . '</span>'
                . ' <span class="text-slate-400">· added ' . e(substr((string) $p['created_at'], 0, 10)) . ' · ' . $used . '</span></span>'
                . '<span class="flex gap-2">'
                . '<form method="post" action="/account/passkeys/' . (int) $p['id'] . '/rename" class="flex gap-1">' . csrf_field()
                . '<input type="text" name="label" maxlength="60" placeholder="Rename" class="form-input form-input-sm w-28">'
                . '<button class="btn btn-ghost btn-sm">Save</button></form>'
                . '<form method="post" action="/account/passkeys/' . (int) $p['id'] . '/delete" data-confirm="Remove this passkey?">' . csrf_field()
                . '<button class="btn btn-ghost btn-sm text-red-600">Remove</button></form>'
                . '</span></li>';
        }
        return '<ul class="divide-y divide-slate-100">' . $rows . '</ul>';
    })()]) ?>
    </div>

</div>

<script nonce="<?= e_attr(nonce()) ?>">
(function () {
    if (!window.PublicKeyCredential) {
        var u = document.querySelector('[data-passkey-unsupported]');
        if (u) u.hidden = false;
    }
})();
</script>
<?php $this->stop(); ?>
