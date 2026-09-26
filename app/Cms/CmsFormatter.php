<?php

declare(strict_types=1);

namespace App\Cms;

/**
 * Turns what a page editor types into the HTML the public site prints. Everything is HTML-escaped first and only then given
 * markup, so nothing an editor types can reach the page as a tag, an attribute or a script.
 *
 * Blocks (separated by a blank line, except fenced code):
 *   # / ## Heading  → <h2>     ### → <h3>     #### → <h4>      (each gets an id for links and the table of contents)
 *   - item / * item → <ul>     1. item → <ol>     > quote → <blockquote>     --- → <hr>
 *   ``` … ```       → <pre><code>            | a | b | tables (second row |---|---|, alignment with :---: )
 *   ![alt](url "caption")      → <figure><img loading=lazy> (url: https://… or a site path — never data:, javascript:, //host)
 *   {{name}} / {{name:arg}}    → a placeholder the renderer fills at request time (see SHORTCODES) — anything not on the
 *                                allowlist, or with a malformed argument, stays visible as plain text
 *   anything else   → <p>, a single newline inside becomes <br>
 * Inline: **bold**  *italic*  `code`  [text](https://…|/path|#anchor|mailto:x@y.z|tel:+911234567890)
 */
final class CmsFormatter
{
    /** @var array<string,string|null> shortcode => pattern its argument must match (null = takes none) */
    public const SHORTCODES = [
        'jobs'     => '/^\d{1,2}$/D',
        'packages' => '/^\d{1,2}$/D',
        'contact'  => '/^[^{}|<>\r\n]{1,60}$/u',
        'phone'    => null,
        'whatsapp' => null,
        'email'    => null,
        'toc'      => null,
        'faq'      => null,
        'youtube'  => '/^[A-Za-z0-9_-]{11}$/D',
        'button'   => '/^(?:https?:\/\/[^\s|{}"<>]{1,300}|\/(?!\/)[^\s|{}"<>]{0,300})\|[^|{}\r\n<>]{1,60}$/u',
        'snippet'  => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
    ];

    private const URL = '(?:https?:\/\/[^\s()*"<>]{1,500}|\/(?!\/)[^\s()*"<>]{0,500}|#[a-z0-9-]{1,80}|mailto:[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,120}\.[A-Za-z]{2,24}|tel:\+?[0-9]{5,15})';

    public static function toHtml(string $source): string
    {
        $source = trim(str_replace(["\r\n", "\r"], "\n", $source));
        if ($source === '') {
            return '';
        }
        $lines = explode("\n", $source);
        $n = count($lines);
        $html = '';
        $ids = [];
        $i = 0;

        while ($i < $n) {
            $line = rtrim($lines[$i]);
            $trim = trim($line);
            if ($trim === '') {
                $i++;
                continue;
            }

            // fenced code — may contain blank lines; an unclosed fence runs to the end
            if (preg_match('/^```[A-Za-z0-9+#_-]{0,20}$/D', $trim) === 1) {
                $code = [];
                for ($i++; $i < $n && rtrim($lines[$i]) !== '```' && trim($lines[$i]) !== '```'; $i++) {
                    $code[] = $lines[$i];
                }
                $i++;
                $html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                continue;
            }

            if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/D', $trim) === 1) {
                $html .= '<hr>';
                $i++;
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+?)\s*#*$/D', $trim, $m) === 1) {
                $tag = 'h' . max(2, strlen($m[1]));
                $html .= "<{$tag} id=\"" . self::headingId($m[2], $ids) . "\">" . self::inline($m[2]) . "</{$tag}>";
                $i++;
                continue;
            }

            if (preg_match('/^\{\{\s*([a-z]+)\s*(?::([^{}]*?))?\s*\}\}$/D', $trim, $m) === 1 && ($ph = self::placeholder($m[1], $m[2] ?? null)) !== null) {
                $html .= $ph;
                $i++;
                continue;
            }

            if (preg_match('/^!\[([^\[\]]{0,160})\]\(((?:https:\/\/[^\s()"<>]{1,400})|(?:\/(?!\/)[^\s()"<>]{1,400}))(?:\s+"([^"]{1,200})")?\)$/Du', $trim, $m) === 1) {
                $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $src = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cap = isset($m[3]) && $m[3] !== '' ? '<figcaption>' . htmlspecialchars($m[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</figcaption>' : '';
                $html .= "<figure><img src=\"{$src}\" alt=\"{$alt}\" loading=\"lazy\" decoding=\"async\">{$cap}</figure>";
                $i++;
                continue;
            }

            // a run of lines of the same kind
            if (str_starts_with($trim, '>')) {
                $quote = [];
                for (; $i < $n && str_starts_with(ltrim($lines[$i]), '>'); $i++) {
                    $quote[] = trim((string) preg_replace('/^\s*>\s?/', '', $lines[$i]));
                }
                $html .= '<blockquote><p>' . implode('<br>', array_map(static fn (string $l): string => self::inline($l), $quote)) . '</p></blockquote>';
                continue;
            }
            if (preg_match('/^[-*]\s+\S/', $trim) === 1) {
                $items = [];
                for (; $i < $n && preg_match('/^\s*[-*]\s+\S/', $lines[$i]) === 1; $i++) {
                    $items[] = '<li>' . self::inline((string) preg_replace('/^\s*[-*]\s+/', '', rtrim($lines[$i]), 1)) . '</li>';
                }
                $html .= '<ul>' . implode('', $items) . '</ul>';
                continue;
            }
            if (preg_match('/^\d{1,3}[.)]\s+\S/', $trim) === 1) {
                $items = [];
                for (; $i < $n && preg_match('/^\s*\d{1,3}[.)]\s+\S/', $lines[$i]) === 1; $i++) {
                    $items[] = '<li>' . self::inline((string) preg_replace('/^\s*\d{1,3}[.)]\s+/', '', rtrim($lines[$i]), 1)) . '</li>';
                }
                $html .= '<ol>' . implode('', $items) . '</ol>';
                continue;
            }
            if (str_contains($trim, '|') && $i + 1 < $n && self::isSeparator($lines[$i + 1])) {
                $table = self::table($lines, $i);
                $html .= $table['html'];
                $i = $table['next'];
                continue;
            }

            // paragraph: up to the next blank line
            $para = [];
            for (; $i < $n && trim($lines[$i]) !== ''; $i++) {
                $para[] = rtrim($lines[$i]);
            }
            $html .= '<p>' . implode('<br>', array_map(static fn (string $l): string => self::inline($l), $para)) . '</p>';
        }

        return $html;
    }

    /** Words an editor would count: letters and digits of the visible text (placeholders and tags excluded). */
    public static function wordCount(string $html): int
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<div class="cms-sc"[^>]*></div>#', ' ', str_replace(['</p>', '</li>', '</h2>', '</h3>', '</h4>', '<br>', '</td>', '</th>'], ' ', $html))), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (int) preg_match_all('/[\p{L}\p{N}]+/u', $text);
    }

    /** Plain text of the formatted body, for a description when the editor wrote none. */
    public static function plainText(string $html, int $max = 160): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<div class="cms-sc"[^>]*></div>#', ' ', str_replace(['</p>', '</li>', '</h2>', '</h3>', '</h4>', '<br>'], ' ', $html))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
    }

    // ---- pieces ----------------------------------------------------------------------------------------------

    private static function placeholder(string $name, ?string $arg): ?string
    {
        if (!array_key_exists($name, self::SHORTCODES)) {
            return null;
        }
        $pattern = self::SHORTCODES[$name];
        $arg = trim($arg ?? '');
        if ($pattern === null) {
            if ($arg !== '') {
                return null;                        // a shortcode that takes no argument was given one
            }
        } elseif (!($name === 'contact' && $arg === '') && preg_match($pattern, $arg) !== 1) {
            return null;                            // {{contact}} may stand alone; every other argument must match its pattern
        }

        return '<div class="cms-sc" data-sc="' . $name . '" data-a="' . htmlspecialchars($arg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></div>';
    }

    /** @param array<string,true> $ids */
    private static function headingId(string $text, array &$ids): string
    {
        $plain = (string) preg_replace('/[*`\[\]()]/', '', $text);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plain) : $plain)), '-');
        $base = substr($base !== '' ? $base : 'section', 0, 60);
        $id = $base;
        for ($k = 2; isset($ids[$id]); $k++) {
            $id = $base . '-' . $k;
        }
        $ids[$id] = true;

        return $id;
    }

    private static function isSeparator(string $line): bool
    {
        return preg_match('/^\s*\|?\s*:?-{2,}:?\s*(?:\|\s*:?-{2,}:?\s*)*\|?\s*$/D', $line) === 1 && str_contains($line, '-');
    }

    /** @return list<string> */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line) ?? '';

        return array_map('trim', explode('|', $line));
    }

    /**
     * @param list<string> $lines
     * @return array{html:string,next:int}
     */
    private static function table(array $lines, int $start): array
    {
        $head = self::cells($lines[$start]);
        $aligns = array_map(static function (string $c): string {
            $c = trim($c);

            return match (true) {
                str_starts_with($c, ':') && str_ends_with($c, ':') => 'text-center',
                str_ends_with($c, ':') => 'text-right',
                default => 'text-left',
            };
        }, self::cells($lines[$start + 1]));
        $cols = count($head);
        $html = '<div class="cms-table"><table><thead><tr>';
        foreach ($head as $k => $c) {
            $html .= '<th class="' . ($aligns[$k] ?? 'text-left') . '" scope="col">' . self::inline($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        $i = $start + 2;
        for ($rows = 0; $i < count($lines) && trim($lines[$i]) !== '' && str_contains($lines[$i], '|') && $rows < 200; $i++, $rows++) {
            $cells = array_pad(array_slice(self::cells($lines[$i]), 0, $cols), $cols, '');
            $html .= '<tr>';
            foreach ($cells as $k => $c) {
                $html .= '<td class="' . ($aligns[$k] ?? 'text-left') . '">' . self::inline($c) . '</td>';
            }
            $html .= '</tr>';
        }

        return ['html' => $html . '</tbody></table></div>', 'next' => $i];
    }

    private static function inline(string $text): string
    {
        $text = str_replace(["", ""], '', $text);   // our own code-span markers can never come from the editor
        // code spans first, so nothing inside them is formatted
        $codes = [];
        $text = (string) preg_replace_callback('/`([^`\n]{1,200})`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

            return "\x01" . (count($codes) - 1) . "\x02";
        }, $text);

        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // links on the escaped text: the URL can no longer contain a quote or an angle bracket
        $text = (string) preg_replace_callback(
            '/\[([^\[\]]{1,200})\]\((' . self::URL . ')\)/',
            static function (array $m): string {
                $u = $m[2];
                $external = str_starts_with($u, 'http');

                return '<a href="' . $u . '"' . ($external ? ' rel="noopener"' : '') . '>' . $m[1] . '</a>';
            },
            $text,
        );
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])/', '<em>$1</em>', $text);

        return (string) preg_replace_callback('/\x01(\d+)\x02/', static fn (array $m): string => $codes[(int) $m[1]] ?? '', $text);
    }
}
