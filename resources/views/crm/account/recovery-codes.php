<?php
/** @var list<string> $codes */
$this->layout('layouts.app', ['title' => 'Recovery codes', 'currentPath' => '/account/security']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Save your recovery codes',
    'breadcrumbs' => [['label' => 'Account'], ['label' => 'Security', 'href' => '/account/security'], ['label' => 'Recovery codes']],
]) ?>

<div class="max-w-lg card card-body">
    <?= component('alert', ['type' => 'warning', 'message' => 'These codes are shown only once. Store them somewhere safe — each one can be used to sign in if you lose your authenticator.']) ?>

    <div class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm">
        <?php foreach ($codes as $code): ?>
            <div class="rounded bg-slate-50 p-2 text-center tracking-wider select-all"><?= e($code) ?></div>
        <?php endforeach ?>
    </div>

    <div class="mt-5 flex gap-2">
        <a href="/account/security" class="btn btn-primary">I've saved these</a>
    </div>
</div>
<?php $this->stop(); ?>
