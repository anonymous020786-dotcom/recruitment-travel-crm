<?php

declare(strict_types=1);

namespace App\Cms;

use App\Repositories\CmsSiteRepository;
use App\Repositories\PublicCatalogRepository;
use App\Support\PublicFormat;

/**
 * The request-time half of page rendering. CmsFormatter stored the body as safe HTML with placeholders for the dynamic
 * parts; this fills them in — the latest jobs and packages, the table of contents, contact links from Admin → Settings, the
 * page's FAQ, buttons and video cards. Every value that reaches the output is escaped here; nothing an editor typed is trusted.
 */
final class CmsRenderer
{
    public function __construct(private readonly PublicCatalogRepository $catalog, private readonly CmsSiteRepository $site)
    {
    }

    /** @param list<array{q:string,a:string}> $faq */
    public function render(string $html, array $faq = []): string
    {
        if (!str_contains($html, 'cms-sc')) {
            return $html;
        }
        $headings = self::headings($html);

        return (string) preg_replace_callback(
            '#<div class="cms-sc" data-sc="([a-z]+)" data-a="([^"]*)"></div>#',
            fn (array $m): string => $this->fill($m[1], html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $headings, $faq),
            $html,
        );
    }

    /** @return list<array{level:int,id:string,text:string}> */
    public static function headings(string $html): array
    {
        preg_match_all('#<h([234]) id="([^"]+)">(.*?)</h\1>#s', $html, $m, PREG_SET_ORDER);

        return array_map(static fn (array $h): array => ['level' => (int) $h[1], 'id' => $h[2], 'text' => trim(html_entity_decode(strip_tags($h[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))], $m);
    }

    // ---- shortcodes ----------------------------------------------------------------------------------------------

    /**
     * @param list<array{level:int,id:string,text:string}> $headings
     * @param list<array{q:string,a:string}> $faq
     */
    private function fill(string $name, string $arg, array $headings, array $faq): string
    {
        return match ($name) {
            'jobs' => $this->jobs(max(1, min((int) $arg, 12))),
            'packages' => $this->packages(max(1, min((int) $arg, 12))),
            'contact' => '<p><a class="btn btn-primary" href="/contact">' . self::e($arg !== '' ? $arg : 'Contact us') . '</a></p>',
            'phone' => $this->contactLink('business.phone', 'tel:'),
            'whatsapp' => $this->whatsapp(),
            'email' => $this->contactLink('business.email', 'mailto:'),
            'toc' => $this->toc($headings),
            'faq' => self::faq($faq),
            'youtube' => '<p><a class="cms-card" href="https://www.youtube.com/watch?v=' . self::e($arg) . '" rel="noopener">▶ Watch the video on YouTube</a></p>',
            'button' => $this->button($arg),
            // a snippet's own placeholders are filled too, but a snippet can never pull in another snippet (no loops)
            'snippet' => $this->renderSnippet($arg, $headings, $faq),
            default => '',
        };
    }

    /**
     * @param list<array{level:int,id:string,text:string}> $headings
     * @param list<array{q:string,a:string}> $faq
     */
    private function renderSnippet(string $key, array $headings, array $faq): string
    {
        $html = $this->site->activeSnippetHtml($key);
        if ($html === null || $html === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '#<div class="cms-sc" data-sc="([a-z]+)" data-a="([^"]*)"></div>#',
            fn (array $m): string => $m[1] === 'snippet' ? '' : $this->fill($m[1], html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $headings, $faq),
            $html,
        );
    }

    private function jobs(int $n): string
    {
        $rows = $this->catalog->jobs('', '', 1, $n)['rows'];
        if ($rows === []) {
            return '<p class="text-sm text-slate-500">No jobs are open right now — check back soon.</p>';
        }
        $out = '<ul class="cms-cards">';
        foreach ($rows as $j) {
            $salary = PublicFormat::salary($j['salary_min'] ?? null, $j['salary_max'] ?? null, $j['currency'] ?? null);
            $out .= '<li><a class="cms-card" href="/overseas-jobs/' . self::e(rawurlencode((string) $j['slug'])) . '"><strong>' . self::e((string) $j['title']) . '</strong>'
                . '<span class="block text-sm text-slate-600">' . self::e(implode(' · ', array_filter([(string) ($j['country_name'] ?? ''), (string) ($j['city'] ?? ''), $salary ?? '']))) . '</span></a></li>';
        }

        return $out . '</ul>';
    }

    private function packages(int $n): string
    {
        $rows = $this->catalog->packages('', 1, $n)['rows'];
        if ($rows === []) {
            return '<p class="text-sm text-slate-500">No packages are on sale right now — check back soon.</p>';
        }
        $out = '<ul class="cms-cards">';
        foreach ($rows as $p) {
            $price = PublicFormat::price($p['price'] ?? null, $p['currency'] ?? null);
            $days = (int) ($p['duration_days'] ?? 0);
            $out .= '<li><a class="cms-card" href="/travel-packages/' . self::e(rawurlencode((string) $p['slug'])) . '"><strong>' . self::e((string) $p['name']) . '</strong>'
                . '<span class="block text-sm text-slate-600">' . self::e(implode(' · ', array_filter([(string) ($p['destination'] ?? ''), $days > 0 ? $days . ' days' : '', $price ?? '']))) . '</span></a></li>';
        }

        return $out . '</ul>';
    }

    private function contactLink(string $setting, string $scheme): string
    {
        $v = trim((string) setting($setting, ''));
        if ($v === '') {
            return '';
        }
        $target = $scheme === 'tel:' ? (string) preg_replace('/[^0-9+]/', '', $v) : $v;

        return '<a href="' . self::e($scheme . $target) . '">' . self::e($v) . '</a>';
    }

    private function whatsapp(): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) setting('business.whatsapp', ''));

        return $digits === '' ? '' : '<p><a class="btn btn-primary" href="https://wa.me/' . self::e($digits) . '" rel="noopener">Chat on WhatsApp</a></p>';
    }

    private function button(string $arg): string
    {
        [$url, $label] = array_pad(explode('|', $arg, 2), 2, '');
        $external = str_starts_with($url, 'http');

        return '<p><a class="btn btn-primary" href="' . self::e($url) . '"' . ($external ? ' rel="noopener"' : '') . '>' . self::e($label) . '</a></p>';
    }

    /** @param list<array{level:int,id:string,text:string}> $headings */
    private function toc(array $headings): string
    {
        $items = array_filter($headings, static fn (array $h): bool => $h['level'] <= 3);
        if (count($items) < 2) {
            return '';
        }
        $li = '';
        foreach ($items as $h) {
            $li .= '<li' . ($h['level'] === 3 ? ' class="ml-4"' : '') . '><a href="#' . self::e($h['id']) . '">' . self::e($h['text']) . '</a></li>';
        }

        return '<nav class="cms-toc" aria-label="On this page"><p class="font-semibold text-slate-900">On this page</p><ol>' . $li . '</ol></nav>';
    }

    /** @param list<array{q:string,a:string}> $faq */
    public static function faq(array $faq): string
    {
        if ($faq === []) {
            return '';
        }
        $out = '<section class="cms-faq" aria-label="Frequently asked questions"><h2 id="faq">Frequently asked questions</h2><div class="space-y-2">';
        foreach ($faq as $f) {
            $answer = implode('', array_map(static fn (string $p): string => '<p>' . self::e($p) . '</p>', array_filter(array_map('trim', explode("\n", $f['a'])), static fn (string $p): bool => $p !== '')));
            $out .= '<details><summary>' . self::e($f['q']) . '</summary><div class="mt-2">' . $answer . '</div></details>';
        }

        return $out . '</div></section>';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
