<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Response;
use App\Repositories\BlogRepository;
use App\Repositories\CmsPageRepository;
use App\Repositories\PublicCatalogRepository;

/** robots.txt and sitemap.xml (static pages plus every open public job and active public package). */
final class SeoController extends Controller
{
    public function __construct(private readonly PublicCatalogRepository $catalog, private readonly BlogRepository $blog, private readonly CmsPageRepository $cms)
    {
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *'];
        foreach ((array) config('seo.robots_disallow', []) as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = 'Allow: /$';
        $lines[] = '';
        $lines[] = 'Sitemap: ' . rtrim((string) config('app.url', ''), '/') . '/sitemap.xml';

        return Response::text(implode("\n", $lines) . "\n")
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }

    public function sitemap(): Response
    {
        $base = rtrim((string) config('app.url', ''), '/');
        $now = gmdate('Y-m-d');

        $urls = [
            ['loc' => $base . '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => $base . '/about', 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => $base . '/contact', 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => $base . '/overseas-jobs', 'priority' => '0.9', 'changefreq' => 'daily'],
            ['loc' => $base . '/travel-packages', 'priority' => '0.8', 'changefreq' => 'weekly'],
            ['loc' => $base . '/blog', 'priority' => '0.6', 'changefreq' => 'weekly'],
        ];
        // Every open public job and active public package (a sitemap must only list URLs that answer 200 to an
        // anonymous visitor — PageAuditTest checks the static ones, the catalogue query only returns visible rows).
        foreach ($this->catalog->jobSitemap() as $j) {
            $urls[] = ['loc' => $base . '/overseas-jobs/' . rawurlencode($j['slug']), 'priority' => '0.7', 'changefreq' => 'weekly', 'lastmod' => substr($j['updated_at'], 0, 10)];
        }
        foreach ($this->catalog->packageSitemap() as $p) {
            $urls[] = ['loc' => $base . '/travel-packages/' . rawurlencode($p['slug']), 'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => substr($p['updated_at'], 0, 10)];
        }

        foreach ($this->blog->sitemap() as $b) {
            $urls[] = ['loc' => $base . '/blog/' . rawurlencode($b['slug']), 'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => substr($b['updated_at'], 0, 10)];
        }

        foreach ($this->cms->sitemap() as $c) {
            $urls[] = ['loc' => $base . '/' . $c['path'], 'priority' => $c['priority'], 'changefreq' => $c['changefreq'], 'lastmod' => substr($c['updated_at'], 0, 10)];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= "  <url><loc>" . e($u['loc']) . "</loc><lastmod>" . ($u['lastmod'] ?? $now) . "</lastmod>"
                . "<changefreq>{$u['changefreq']}</changefreq><priority>{$u['priority']}</priority></url>\n";
        }
        $xml .= '</urlset>' . "\n";

        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8'])
            ->withHeader('Cache-Control', 'public, max-age=' . (int) config('seo.sitemap_cache_seconds', 3600));
    }
}
