<?php
/** @var string $secret @var string $uri @var string $display */
$this->layout('layouts.app', ['title' => 'Set up two-factor', 'currentPath' => '/account/security']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Set up authenticator app',
    'breadcrumbs' => [['label' => 'Account'], ['label' => 'Security', 'href' => '/account/security'], ['label' => 'Two-factor']],
]) ?>

<div class="max-w-lg card card-body space-y-4">
    <ol class="list-decimal space-y-3 pl-5 text-sm text-slate-700">
        <li>
            Open your authenticator app (Google Authenticator, Authy, 1Password, …) and add an account.
        </li>
        <li>
            Scan the QR code, or enter this key manually:
            <div class="mt-1 font-mono text-base tracking-wider text-slate-900 bg-slate-50 rounded p-2 select-all"><?= e($display) ?></div>
            <a href="<?= e_attr($uri) ?>" class="mt-1 inline-block text-xs text-brand-600 hover:underline">Open in app (mobile)</a>
        </li>
        <li>
            Enter the 6-digit code the app shows to confirm:
        </li>
    </ol>

    <form method="post" action="/account/two-factor" data-once>
        <?= csrf_field() ?>
        <?= component('field', ['name' => 'code', 'label' => 'Code from app', 'required' => true, 'attrs' => 'inputmode="numeric" autocomplete="one-time-code" autofocus maxlength="8"']) ?>
        <div class="flex gap-2">
            <button class="btn btn-primary">Confirm &amp; enable</button>
            <a href="/account/security" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php $this->stop(); ?>
