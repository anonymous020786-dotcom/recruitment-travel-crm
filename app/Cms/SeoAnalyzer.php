<?php

declare(strict_types=1);

namespace App\Cms;

/**
 * The on-page SEO checklist shown beside the editor. Pure functions of the page's own fields (plus one number from the
 * database: how many other pages share the title), so it is fast and testable. It advises; it never blocks saving.
 */
final class SeoAnalyzer
{
    /**
     * @param array<string,mixed> $page title, path, meta_title, meta_description, focus_keyword, body_html, featured_image, featured_alt, robots
     * @return array{score:int,checks:list<array{level:string,text:string}>}
     */
    public static function analyze(array $page, int $otherPagesWithSameTitle = 0): array
    {
        $checks = [];
        $add = static function (string $level, string $text) use (&$checks): void {
            $checks[] = ['level' => $level, 'text' => $text];
        };

        $title = trim((string) (($page['meta_title'] ?? '') !== '' ? $page['meta_title'] : ($page['title'] ?? '')));
        $desc = trim((string) ($page['meta_description'] ?? ''));
        $html = (string) ($page['body_html'] ?? '');
        $words = CmsFormatter::wordCount($html);
        $tl = mb_strlen($title);
        $dl = mb_strlen($desc);

        // title
        if ($tl >= 30 && $tl <= 60) {
            $add('ok', "The search title is {$tl} characters — a good length.");
        } elseif ($tl < 20 || $tl > 70) {
            $add('bad', "The search title is {$tl} characters; aim for 30–60 so it is not cut off or too thin.");
        } else {
            $add('warn', "The search title is {$tl} characters; 30–60 is ideal.");
        }
        if ($otherPagesWithSameTitle > 0) {
            $add('warn', 'Another page uses the same search title — give each page its own.');
        }

        // description
        if ($dl === 0) {
            $add('bad', 'No meta description — search engines will pick a random snippet. Write 70–160 characters.');
        } elseif ($dl >= 70 && $dl <= 160) {
            $add('ok', "The meta description is {$dl} characters — a good length.");
        } else {
            $add('warn', "The meta description is {$dl} characters; 70–160 reads best in search results.");
        }

        // content
        if ($words >= 300) {
            $add('ok', "{$words} words of content.");
        } elseif ($words >= 150) {
            $add('warn', "{$words} words — thin content ranks poorly; 300+ is better.");
        } else {
            $add('bad', "Only {$words} words — add substance (300+ is a good aim).");
        }
        preg_match_all('#<h2[ >]#', $html, $h2);
        $add(count($h2[0]) >= 1 ? 'ok' : 'warn', count($h2[0]) >= 1 ? 'Has sub-headings that structure the page.' : 'No sub-headings (## Heading) — they help readers and search engines.');
        preg_match_all('#href="/(?!/)#', $html, $links);
        $add(count($links[0]) >= 1 ? 'ok' : 'warn', count($links[0]) >= 1 ? 'Links to other pages on your site.' : 'No links to your other pages — add one or two.');

        // image
        if (($page['featured_image'] ?? '') === '') {
            $add('warn', 'No featured image — link previews on WhatsApp and social media fall back to the default card.');
        } elseif (trim((string) ($page['featured_alt'] ?? '')) === '') {
            $add('warn', 'The featured image has no description (alt text).');
        } else {
            $add('ok', 'Featured image with a description.');
        }

        // readability
        $text = CmsFormatter::plainText($html, 100000);
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($sentences !== [] && $words >= 60) {
            $avg = (int) round($words / count($sentences));
            $add($avg <= 20 ? 'ok' : ($avg <= 28 ? 'warn' : 'bad'), "Sentences average {$avg} words" . ($avg <= 20 ? ' — easy to read.' : '; shorter sentences read better.'));
        }

        // focus keyword
        $kw = mb_strtolower(trim((string) ($page['focus_keyword'] ?? '')));
        if ($kw !== '') {
            $first = mb_strtolower(CmsFormatter::plainText(explode('</p>', $html)[0], 400));
            $slugKw = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($kw)), '-');
            $where = [
                'the search title' => str_contains(mb_strtolower($title), $kw),
                'the meta description' => str_contains(mb_strtolower($desc), $kw),
                'the first paragraph' => str_contains($first, $kw),
                'the address' => $slugKw !== '' && str_contains((string) ($page['path'] ?? ''), $slugKw),
                'a heading' => preg_match('#<h[234][^>]*>[^<]*' . preg_quote(htmlspecialchars($kw, ENT_QUOTES), '#') . '#iu', $html) === 1,
            ];
            foreach ($where as $place => $found) {
                $add($found ? 'ok' : 'warn', $found ? "The focus keyword appears in {$place}." : "The focus keyword is missing from {$place}.");
            }
        }

        // path + indexing
        if (mb_strlen((string) ($page['path'] ?? '')) > 75) {
            $add('warn', 'The address is long; shorter URLs are easier to share.');
        }
        if (($page['robots'] ?? 'index') === 'noindex') {
            $add('warn', 'This page is set to noindex — search engines will not list it.');
        }

        $weights = ['ok' => 1.0, 'warn' => 0.5, 'bad' => 0.0];
        $score = $checks === [] ? 0 : (int) round(100 * array_sum(array_map(static fn (array $c): float => $weights[$c['level']], $checks)) / count($checks));

        return ['score' => $score, 'checks' => $checks];
    }
}
