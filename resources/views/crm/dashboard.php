<?php
$this->layout('layouts.app', ['title' => 'Dashboard', 'currentPath' => '/dashboard']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Dashboard',
    'subtitle' => 'Welcome back, ' . e(user()?->name ?? ''),
]) ?>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?= component('stat', ['label' => 'New leads (7d)', 'value' => '—', 'hint' => 'Wired in Phase 10']) ?>
    <?= component('stat', ['label' => 'Active candidates', 'value' => '—']) ?>
    <?= component('stat', ['label' => "Today's follow-ups", 'value' => '—']) ?>
    <?= component('stat', ['label' => 'Visa processing', 'value' => '—']) ?>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <?= component('card', [
            'title' => 'Recent activity',
            'body' => component('empty-state', [
                'title' => 'Nothing to show yet',
                'message' => 'Operational widgets and charts are delivered in Phase 10.',
            ]),
        ]) ?>
    </div>
    <?= component('card', [
        'title' => 'Your access',
        'body' => '<p class="text-sm text-slate-600">Role: <span class="font-medium text-slate-900">'
            . e(user()?->roleName ?? '') . '</span></p>'
            . '<p class="mt-2 text-sm text-slate-600">Foundation (Phase 1) is complete: auth, RBAC, '
            . 'sessions, CSRF, rate limiting, audit, and this component library.</p>',
    ]) ?>
</div>
<?php $this->stop(); ?>
