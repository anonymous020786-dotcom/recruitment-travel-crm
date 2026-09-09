<?php /** @var string $title */ ?>
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Sign in') ?> &middot; <?= e((string) config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e_attr(asset('app.css')) ?>">
</head>
<body class="h-full">
<div class="flex min-h-full items-center justify-center p-6">
    <main class="w-full max-w-sm">
        <div class="mb-6 flex items-center gap-2">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">CRM</span>
            <span class="text-sm font-semibold text-slate-900"><?= e((string) config('app.name')) ?></span>
        </div>

        <div class="card">
            <div class="card-body">
                <h1 class="text-base font-semibold text-slate-900"><?= e($title ?? 'Sign in') ?></h1>
                <p class="mt-0.5 mb-5 text-sm text-slate-500"><?= e($subtitle ?? 'Enter your details to continue.') ?></p>

                <?php if ($status = session()?->get('status')): ?>
                    <div class="mb-4"><?= component('alert', ['type' => 'success', 'message' => (string) $status]) ?></div>
                <?php endif ?>

                <?php if ($generic = error('form')): ?>
                    <div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $generic]) ?></div>
                <?php endif ?>

                <?= $this->yield('content') ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
