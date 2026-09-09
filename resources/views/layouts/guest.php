<?php /** @var string $title */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Sign in') ?> &middot; <?= e((string) config('app.name')) ?></title>
    <style nonce="<?= e_attr($cspNonce ?? '') ?>">
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               background: #f3f4f6; color: #111827; display: flex; min-height: 100vh;
               align-items: center; justify-content: center; padding: 1.5rem; }
        .card { width: 100%; max-width: 24rem; background: #fff; border: 1px solid #e5e7eb;
                border-radius: 12px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        h1 { font-size: 1.15rem; margin: 0 0 .35rem; }
        .sub { color: #6b7280; margin: 0 0 1.5rem; font-size: .9rem; }
        label { display: block; font-weight: 600; font-size: .82rem; margin: 0 0 .35rem; }
        input[type=email], input[type=password], input[type=text] {
            width: 100%; padding: .6rem .7rem; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: .95rem; }
        input:focus { outline: 2px solid #2563eb; outline-offset: 1px; border-color: #2563eb; }
        .field { margin-bottom: 1rem; }
        .err { color: #b91c1c; font-size: .8rem; margin: .3rem 0 0; }
        button { width: 100%; padding: .65rem 1rem; background: #2563eb; color: #fff; border: 0;
                 border-radius: 8px; font-size: .95rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .row { display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;
               font-size: .85rem; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .alert { padding: .7rem .8rem; border-radius: 8px; font-size: .85rem; margin-bottom: 1rem; }
        .alert-ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <main class="card">
        <h1><?= e($title ?? 'Sign in') ?></h1>
        <p class="sub"><?= e((string) config('app.name')) ?></p>

        <?php if ($status = session()?->get('status')): ?>
            <div class="alert alert-ok"><?= e((string) $status) ?></div>
        <?php endif ?>

        <?php if ($generic = error('email') ?? error('form')): ?>
            <div class="alert alert-err"><?= e($generic) ?></div>
        <?php endif ?>

        <?= $this->yield('content') ?>
    </main>
</body>
</html>
