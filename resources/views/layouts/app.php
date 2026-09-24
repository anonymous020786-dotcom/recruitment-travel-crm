<?php
/**
 * Authenticated CRM shell: fixed sidebar (desktop) / off-canvas drawer (mobile),
 * topbar with page title + user menu, main content region.
 *
 * Layout data: title, breadcrumbs (optional), currentPath (for active nav).
 */
$title = $title ?? 'CRM';
$currentPath = $currentPath ?? (app()->bound(App\Http\Request::class) ? app(App\Http\Request::class)->path() : '/');
$nav = array_filter(
    (array) config('navigation', []),
    static fn ($item) => can((string) $item['permission']),
);
$me = user();
$n = nonce();

$navMarkup = static function (array $nav, string $currentPath): string {
    $out = '';
    foreach ($nav as $item) {
        $active = $item['path'] === '/dashboard'
            ? $currentPath === '/dashboard'
            : str_starts_with($currentPath, rtrim($item['path'], '/'));
        $out .= '<a href="' . e_url($item['path']) . '" class="nav-link' . ($active ? ' is-active' : '') . '"'
            . ($active ? ' aria-current="page"' : '') . '>'
            . component('icon', ['name' => $item['icon'], 'class' => 'h-5 w-5 shrink-0'])
            . '<span>' . e($item['label']) . '</span></a>';
    }
    return $out;
};
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="<?= e_attr((string) config('seo.crm_robots', 'noindex, nofollow')) ?>">
    <meta name="csrf-token" content="<?= e_attr(csrf_token()) ?>">
    <title><?= e($title) ?> &middot; <?= e((string) config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e_attr(asset('app.css')) ?>">
</head>
<body class="h-full">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:ring-2 focus:ring-brand-500">Skip to content</a>

<div class="min-h-full lg:flex">

    <!-- Mobile backdrop -->
    <div data-nav-backdrop hidden class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"></div>

    <!-- Sidebar / drawer -->
    <aside data-nav-drawer
           class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full transform bg-white ring-1 ring-slate-200 transition-transform duration-200 data-[open]:translate-x-0 lg:static lg:translate-x-0 lg:transform-none">
        <div class="flex h-14 items-center gap-2 border-b border-slate-200 px-4">
            <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-600 text-xs font-bold text-white">CRM</span>
            <span class="text-sm font-semibold text-slate-900 truncate"><?= e((string) config('app.name')) ?></span>
        </div>
        <nav aria-label="Main" class="flex flex-col gap-0.5 overflow-y-auto p-2" style="max-height: calc(100vh - 3.5rem)">
            <?= $navMarkup($nav, $currentPath) ?>
        </nav>
    </aside>

    <!-- Main column -->
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur">
            <button data-nav-toggle type="button" class="btn btn-ghost btn-sm lg:hidden" aria-label="Open navigation">
                <?= component('icon', ['name' => 'menu', 'class' => 'h-5 w-5']) ?>
            </button>
            <p class="truncate text-sm font-semibold text-slate-900"><?= e($title) ?></p>

            <div class="ml-auto flex items-center gap-1">
                <a href="/search" class="btn btn-ghost btn-sm" aria-label="Search"><?= component('icon', ['name' => 'search', 'class' => 'h-5 w-5']) ?></a>
                <a href="/notifications" class="btn btn-ghost btn-sm" aria-label="Notifications"><?= component('icon', ['name' => 'bell', 'class' => 'h-5 w-5']) ?></a>

                <div data-dropdown class="relative">
                    <button data-dropdown-trigger type="button" aria-expanded="false"
                            class="btn btn-ghost btn-sm gap-2">
                        <span class="grid h-6 w-6 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                            <?= e(strtoupper(substr($me?->name ?? '?', 0, 1))) ?>
                        </span>
                        <span class="hidden sm:inline max-w-[10rem] truncate"><?= e($me?->name ?? '') ?></span>
                    </button>
                    <div data-dropdown-menu hidden
                         class="absolute right-0 mt-1 w-52 rounded-lg bg-white p-1 text-sm ring-1 ring-slate-200 shadow-lg">
                        <div class="px-3 py-2">
                            <p class="font-medium text-slate-900 truncate"><?= e($me?->name ?? '') ?></p>
                            <p class="text-xs text-slate-500 truncate"><?= e($me?->email ?? '') ?> &middot; <?= e($me?->roleName ?? '') ?></p>
                        </div>
                        <div class="my-1 border-t border-slate-100"></div>
                        <a href="/account/profile" class="block rounded-md px-3 py-1.5 hover:bg-slate-100">Profile</a>
                        <a href="/account/security" class="block rounded-md px-3 py-1.5 hover:bg-slate-100">Password &amp; security</a>
                        <form method="post" action="/logout" data-once>
                            <input type="hidden" name="_token" value="<?= e_attr(csrf_token()) ?>">
                            <button type="submit" class="flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-left text-red-600 hover:bg-red-50">
                                <?= component('icon', ['name' => 'logout', 'class' => 'h-4 w-4']) ?> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <?= component('toasts') ?>

        <?php
        $graceLeft = app()->bound(App\Http\Request::class)
            ? app(App\Http\Request::class)->attribute('twofa_grace_left')
            : null;
        if (is_int($graceLeft)):
        ?>
            <div class="border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 sm:px-6">
                <?php if ($graceLeft > 0): ?>
                    Two-factor authentication is required for your role.
                    <strong><?= $graceLeft ?> sign-in<?= $graceLeft === 1 ? '' : 's' ?></strong> left before it becomes mandatory.
                <?php else: ?>
                    Two-factor authentication is now required.
                <?php endif ?>
                <a href="/account/two-factor" class="font-semibold underline">Set it up now</a>.
            </div>
        <?php endif ?>

        <main id="main" class="mx-auto w-full max-w-7xl flex-1 p-4 sm:p-6">
            <?= $this->yield('content') ?>
        </main>
    </div>
</div>

<script src="<?= e_attr(asset('app.js')) ?>" nonce="<?= e_attr($n) ?>" defer></script>
</body>
</html>
