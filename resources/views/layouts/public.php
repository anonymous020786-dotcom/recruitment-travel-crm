<?php
/**
 * Public marketing layout — indexable, cacheable, third-party integrations
 * allowed (GA / Tawk / Turnstile) via the public CSP additions.
 *
 * Layout data: title, description, canonical (path), ogImage (optional).
 */
$title = $title ?? (string) config('seo.default_title');
$description = $description ?? (string) config('seo.default_description');
$appName = (string) config('app.name');
$canonical = str_starts_with((string) ($canonical ?? ''), 'https://') ? (string) $canonical : rtrim((string) config('app.url', ''), '/') . '/' . ltrim($canonical ?? '', '/');
$chrome = $chrome ?? true;   // false: a landing page without the menu and footer
$nav = [
    '/' => 'Home',
    '/overseas-jobs' => 'Jobs',
    '/travel-packages' => 'Travel',
    '/about' => 'About',
    '/contact' => 'Contact',
];
// Admin → Pages → Menus replaces the built-in links once a menu has at least one active item.
$menuRepo = app(App\Repositories\CmsSiteRepository::class);
$headerMenu = $menuRepo->publicMenu('header');
$footerMenu = $menuRepo->publicMenu('footer');
if ($headerMenu !== []) {
    $nav = [];
    foreach ($headerMenu as $item) {
        $nav[$item['url']] = $item['label'];
    }
}
$newTab = array_column(array_merge($headerMenu, $footerMenu), 'new_tab', 'url');
$current = app()->bound(App\Http\Request::class) ? app(App\Http\Request::class)->path() : '/';
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?><?= $title === (string) config('seo.default_title') ? '' : e((string) config('seo.title_suffix')) ?></title>
    <meta name="description" content="<?= e_attr($description) ?>">
    <link rel="canonical" href="<?= e_attr($canonical) ?>">
    <?php if (!empty($robots)): ?><meta name="robots" content="<?= e_attr($robots) ?>"><?php endif ?>
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= e_attr($canonical) ?>">
    <meta property="og:site_name" content="<?= e_attr($appName) ?>">
    <meta property="og:title" content="<?= e_attr($ogTitle ?? $title) ?>">
    <meta property="og:description" content="<?= e_attr($ogDescription ?? $description) ?>">
    <?php
    // The picture in link previews: a page's own image, else the one set in Admin → Settings, else the shipped default card.
    $shareBase = rtrim((string) config('app.url', ''), '/');
    $shareImage = (string) ($ogImage ?? '') !== '' ? (string) $ogImage : (string) setting('business.share_image', '/assets/og-default.png');
    $shareImage = str_starts_with($shareImage, '/') ? $shareBase . $shareImage : $shareImage;
    ?>
    <meta property="og:image" content="<?= e_attr($shareImage) ?>">
    <meta property="og:image:alt" content="<?= e_attr((string) setting('business.name', $appName)) ?>">
    <?php if ($shareImage === $shareBase . '/assets/og-default.png'): ?><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><?php endif ?>
    <meta name="twitter:card" content="summary_large_image">
    <?php
    $siteUrl = rtrim((string) config('app.url', ''), '/');
    $bizPhone = (string) setting('business.phone', '');
    $bizEmail = (string) setting('business.email', '');
    $bizAddress = (string) setting('business.address', '');
    $orgLd = ['@type' => 'Organization', 'name' => (string) setting('business.name', $appName), 'url' => $siteUrl]
        + ($bizPhone !== '' ? ['telephone' => $bizPhone] : []) + ($bizEmail !== '' ? ['email' => $bizEmail] : [])
        + ($bizAddress !== '' ? ['address' => ['@type' => 'PostalAddress', 'streetAddress' => $bizAddress]] : []);
    ?>
    <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => [
        $orgLd,
        ['@type' => 'WebSite', 'name' => $appName, 'url' => $siteUrl],
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <link rel="stylesheet" href="<?= e_attr(asset('app.css')) ?>">
    <?= $this->partial('partials.integrations-head') ?>
    <?= $this->yield('head') ?>
</head>
<body class="flex min-h-full flex-col">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:ring-2 focus:ring-brand-500">Skip to content</a>

<?php if ($chrome): ?>
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
        <a href="/" class="flex items-center gap-2">
            <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-600 text-xs font-bold text-white">CRM</span>
            <span class="text-sm font-semibold text-slate-900"><?= e($appName) ?></span>
        </a>
        <nav aria-label="Primary" class="hidden gap-1 sm:flex">
            <?php foreach ($nav as $href => $label): ?>
                <a href="<?= e_url($href) ?>"<?= !empty($newTab[$href]) ? ' target="_blank" rel="noopener"' : '' ?>
                   class="rounded-lg px-3 py-1.5 text-sm font-medium <?= ($href === '/' ? $current === '/' : str_starts_with($current, $href)) ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach ?>
        </nav>
        <a href="/login" class="btn btn-secondary btn-sm">Staff sign in</a>
    </div>
</header>
<?php endif ?>

<main id="main" class="flex-1">
    <?= $this->yield('content') ?>
</main>

<?php if ($chrome): ?>
<footer class="mt-16 border-t border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:justify-between">
        <p>&copy; <?= date('Y') ?> <?= e((string) setting('business.name', $appName)) ?>. All rights reserved.</p>
        <nav aria-label="Footer" class="flex flex-wrap gap-3">
            <?php if ($bizPhone !== ''): ?><a href="tel:<?= e_attr(preg_replace('/[^0-9+]/', '', $bizPhone)) ?>"><?= e($bizPhone) ?></a><?php endif ?>
            <?php if ($footerMenu !== []): foreach ($footerMenu as $item): ?>
                <a href="<?= e_url($item['url']) ?>"<?= $item['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($item['label']) ?></a>
            <?php endforeach; else: ?>
            <a href="/about">About</a>
            <a href="/overseas-jobs">Jobs</a>
            <a href="/travel-packages">Travel</a>
            <a href="/blog">Blog</a>
            <a href="/contact">Contact</a>
            <?php endif ?>
        </nav>
    </div>
</footer>
<?php endif ?>

<?= $this->partial('partials.integrations-body') ?>
</body>
</html>
