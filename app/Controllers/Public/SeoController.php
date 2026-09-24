<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Response;

/**
 * robots.txt and sitemap.xml. Public jobs / packages / blog URLs are added to
 * the sitemap by their feature phases; for now it lists the static pages.
 */
final class SeoController extends Controller
{
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
            // Public /jobs, /travel-packages and blog URLs are added here when those pages exist — a sitemap must only list
            // URLs that answer 200 to an anonymous visitor (PageAuditTest checks this).
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= "  <url><loc>" . e($u['loc']) . "</loc><lastmod>{$now}</lastmod>"
                . "<changefreq>{$u['changefreq']}</changefreq><priority>{$u['priority']}</priority></url>\n";
        }
        $xml .= '</urlset>' . "\n";

        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8'])
            ->withHeader('Cache-Control', 'public, max-age=' . (int) config('seo.sitemap_cache_seconds', 3600));
    }
}
