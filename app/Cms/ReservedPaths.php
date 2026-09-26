<?php

declare(strict_types=1);

namespace App\Cms;

use App\Http\Router;
use App\Support\Application;

/**
 * First path segments a page may not use, because something else already answers there (a route, an asset folder, a file the
 * browser or a crawler asks for). Taken from the registered routes plus a fixed list, so adding a route can never be
 * shadowed by — or silently lose to — a page.
 */
final class ReservedPaths
{
    private const FIXED = [
        'admin', 'assets', 'api', 'login', 'logout', 'dashboard', 'account', 'storage', 'media', 'uploads', 'preview', 'p', 'pay', 'webhooks',
        'robots.txt', 'sitemap.xml', 'favicon.ico', 'apple-touch-icon.png', '.well-known', 'feed', 'rss', 'forgot-password', 'reset-password',
        'confirm-password', 'two-factor', 'health', 'install', 'index.php', 'cron',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    public function isReserved(string $firstSegment): bool
    {
        return in_array(strtolower($firstSegment), $this->all(), true);
    }

    /** @return list<string> */
    public function all(): array
    {
        $set = array_fill_keys(self::FIXED, true);
        if ($this->app->bound(Router::class)) {
            foreach ($this->app->get(Router::class)->routes() as $route) {
                $first = explode('/', ltrim($route->uri, '/'))[0];
                if ($first !== '' && !str_starts_with($first, '{')) {
                    $set[strtolower($first)] = true;
                }
            }
        }

        return array_keys($set);
    }
}
