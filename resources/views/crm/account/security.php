<?php
/**
 * @var \App\Models\User $user
 * @var array{enabled:bool,method:string,recovery:int,required:bool} $twoFactor
 * @var list<array> $devices @var list<array> $sessions @var int $rememberCount
 */
$this->layout('layouts.app', ['title' => 'Security', 'currentPath' => '/account/security']);
$this->start('content');

$ip = static fn ($bin) => $bin ? (@inet_ntop($bin) ?: '—') : '—';
?>
<?= component('page-header', ['title' => 'Password & security', 'breadcrumbs' => [['label' => 'Account'], ['label' => 'Security']]]) ?>

<div class="grid gap-4 lg:grid-cols-2">

    <?= component('card', ['title' => 'Change password', 'body' =>
        '<form method="post" action="/account/password" data-once>' . csrf_field()
        . component('field', ['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password"'])
        . component('field', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password" minlength="10"', 'hint' => 'At least 10 characters.'])
        . component('field', ['name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password" minlength="10"'])
        . '<button class="btn btn-primary">Update password</button></form>',
    ]) ?>

    <?= component('card', ['title' => 'Two-factor authentication', 'body' => (function () use ($twoFactor) {
        if ($twoFactor['enabled']) {
            $html = '<p class="text-sm">' . component('badge', ['label' => 'Enabled', 'color' => 'emerald', 'dot' => true])
                . ' <span class="text-slate-500">via ' . e($twoFactor['method']) . '</span></p>';
            $html .= '<p class="mt-2 text-sm text-slate-600">' . (int) $twoFactor['recovery'] . ' recovery code(s) remaining.</p>';
            $html .= '<form method="post" action="/account/recovery-codes" class="mt-3">' . csrf_field()
                . '<button class="btn btn-secondary btn-sm">Regenerate codes</button></form>';
            if (!$twoFactor['required']) {
                $html .= '<form method="post" action="/account/two-factor/disable" class="mt-2" data-confirm="Disable two-factor authentication?">' . csrf_field()
                    . '<button class="btn btn-danger btn-sm">Disable</button></form>';
            } else {
                $html .= '<p class="mt-2 text-xs text-slate-400">Required for your role — cannot be disabled.</p>';
            }
            return $html;
        }

        return '<p class="text-sm text-slate-600">Add a second step at sign-in using an authenticator app.</p>'
            . ($twoFactor['required'] ? '<p class="mt-1 text-xs text-amber-600">Required for your role.</p>' : '')
            . '<a href="/account/two-factor" class="btn btn-primary btn-sm mt-3">Set up authenticator app</a>';
    })()]) ?>

    <?= component('card', ['title' => 'Passkeys', 'body' =>
        '<p class="text-sm text-slate-600">Sign in with Face ID, a fingerprint, your phone, or a hardware security key — no password to phish.</p>'
        . '<a href="/account/passkeys" class="btn btn-secondary btn-sm mt-3">Manage passkeys</a>',
    ]) ?>

    <?= component('card', ['title' => 'Trusted devices', 'body' => (function () use ($devices, $ip) {
        if ($devices === []) {
            return '<p class="text-sm text-slate-500">No trusted devices. A device is trusted when you tick "Trust this device" at sign-in.</p>';
        }
        $rows = '';
        foreach ($devices as $d) {
            $rows .= '<li class="flex items-center justify-between gap-2 py-1.5 text-sm">'
                . '<span>' . e($d['label'] ?? 'Device') . ' <span class="text-slate-400">· ' . e($ip($d['last_ip'] ?? null)) . ' · until ' . e(substr((string) $d['trusted_until'], 0, 10)) . '</span></span>'
                . '<form method="post" action="/account/devices/revoke">' . csrf_field()
                . '<input type="hidden" name="id" value="' . (int) $d['id'] . '">'
                . '<button class="btn btn-ghost btn-sm text-red-600">Remove</button></form></li>';
        }
        return '<ul class="divide-y divide-slate-100">' . $rows . '</ul>';
    })()]) ?>

    <?= component('card', ['title' => 'Active sessions', 'body' => (function () use ($sessions, $rememberCount, $ip) {
        $rows = '';
        foreach ($sessions as $s) {
            $rows .= '<li class="flex items-center justify-between gap-2 py-1.5 text-sm">'
                . '<span>' . e($ip($s['ip_address'] ?? null))
                . ' <span class="text-slate-400">· ' . e(mb_substr((string) ($s['user_agent'] ?? ''), 0, 48)) . '</span>'
                . ($s['is_current'] ? ' ' . component('badge', ['label' => 'This device', 'color' => 'blue']) : '') . '</span>'
                . ($s['is_current'] ? '' :
                    '<form method="post" action="/account/sessions/revoke">' . csrf_field()
                    . '<input type="hidden" name="id" value="' . e_attr((string) $s['id']) . '">'
                    . '<button class="btn btn-ghost btn-sm text-red-600">Sign out</button></form>')
                . '</li>';
        }
        $html = '<ul class="divide-y divide-slate-100">' . $rows . '</ul>';
        $html .= '<p class="mt-2 text-xs text-slate-400">' . (int) $rememberCount . ' persistent "remember me" login(s).</p>';
        $html .= '<form method="post" action="/account/sessions/revoke-all" class="mt-3" data-confirm="Sign out of all other devices?">' . csrf_field()
            . '<button class="btn btn-secondary btn-sm">Sign out everywhere else</button></form>';
        return $html;
    })()]) ?>

</div>
<?php $this->stop(); ?>
