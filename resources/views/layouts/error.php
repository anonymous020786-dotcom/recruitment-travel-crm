<?php
/** @var string $title @var string $body @var int $status @var string $ref */
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Error') ?> &middot; <?= e((string) config('app.name', 'CRM')) ?></title>
    <link rel="stylesheet" href="<?= e_attr(asset('app.css')) ?>">
</head>
<body class="h-full">
<div class="flex min-h-full items-center justify-center p-6">
    <div class="w-full max-w-md text-center">
        <p class="text-sm font-semibold text-brand-600"><?= (int) ($status ?? 500) ?></p>
        <h1 class="mt-1 text-xl font-semibold text-slate-900"><?= e($title ?? 'Something went wrong') ?></h1>
        <p class="mt-2 text-sm text-slate-600"><?= e($body ?? '') ?></p>

        <div class="mt-6 flex items-center justify-center gap-3">
            <?= $this->yield('actions', '<a href="/" class="btn btn-primary">Return home</a>') ?>
        </div>

        <?php if (!empty($ref)): ?>
            <p class="mt-8 text-xs text-slate-400">Reference: <?= e($ref) ?></p>
        <?php endif ?>
    </div>
</div>
</body>
</html>
