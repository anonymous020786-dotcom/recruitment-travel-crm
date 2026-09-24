<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns what a blog author types into the HTML the public site prints. Everything is HTML-escaped first and only then
 * given markup, so no author input can ever reach the page as a tag, an attribute or a script.
 *
 * The supported subset (blocks are separated by a blank line):
 *   ## Heading           → <h3>          ### Sub-heading → <h4>
 *   - item / * item      → <ul>          1. item         → <ol>
 *   **bold**  *italic*   → <strong> <em>
 *   [text](https://…)    → <a>  (only http(s):// or a site-relative /path — never javascript:, data:, or //host)
 *   anything else        → <p>, a single newline inside it becomes <br>
 */
final class BlogFormatter
{
    public static function toHtml(string $source): string
    {
        $source = trim(str_replace(["\r\n", "\r"], "\n", $source));
        if ($source === '') {
            return '';
        }

        $html = '';
        foreach (preg_split('/\n{2,}/', $source) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $lines = array_map('rtrim', explode("\n", $block));

            if (preg_match('/^(#{2,3})\s+(.+)$/D', $lines[0], $m) === 1 && count($lines) === 1) {
                $tag = strlen($m[1]) === 2 ? 'h3' : 'h4';
                $html .= "<{$tag}>" . self::inline($m[2]) . "</{$tag}>";
            } elseif (self::allMatch($lines, '/^[-*]\s+\S/')) {
                $html .= '<ul>' . self::items($lines, '/^[-*]\s+/') . '</ul>';
            } elseif (self::allMatch($lines, '/^\d{1,3}[.)]\s+\S/')) {
                $html .= '<ol>' . self::items($lines, '/^\d{1,3}[.)]\s+/') . '</ol>';
            } else {
                $html .= '<p>' . implode('<br>', array_map(static fn (string $l): string => self::inline($l), $lines)) . '</p>';
            }
        }

        return $html;
    }

    /** Plain text of the formatted post, for a search-engine description when the author wrote no excerpt. */
    public static function plainText(string $html, int $max = 160): string
    {
        return PublicFormat::excerpt(str_replace(['</p>', '</h3>', '</h4>', '</li>', '<br>'], ' ', $html), $max);
    }

    /** @param list<string> $lines */
    private static function allMatch(array $lines, string $pattern): bool
    {
        foreach ($lines as $line) {
            if (preg_match($pattern, $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $lines */
    private static function items(array $lines, string $marker): string
    {
        return implode('', array_map(static fn (string $l): string => '<li>' . self::inline((string) preg_replace($marker, '', $l, 1)) . '</li>', $lines));
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Links first, on the escaped text: the URL can no longer contain a quote or angle bracket.
        $text = (string) preg_replace_callback(
            '/\[([^\[\]]{1,200})\]\((https?:\/\/[^\s()*]{1,500}|\/(?!\/)[^\s()*]{0,500})\)/',
            static fn (array $m): string => '<a href="' . $m[2] . '"' . ($m[2][0] === '/' ? '' : ' rel="noopener"') . '>' . $m[1] . '</a>',
            $text,
        );
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $text);

        return (string) preg_replace('/(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])/', '<em>$1</em>', $text);
    }
}
