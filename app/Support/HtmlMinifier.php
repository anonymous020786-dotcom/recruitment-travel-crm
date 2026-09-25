<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A conservative HTML minifier: it shrinks the page without ever changing how it renders.
 *
 *  - HTML comments are removed (conditional comments `<!--[if …]>` are kept);
 *  - runs of whitespace in text collapse to one space, and whitespace that only sits next to a block-level tag (where a
 *    browser ignores it anyway) is dropped — but the single space between two inline elements stays, because it is visible;
 *  - inside a tag, the gaps between attributes collapse; quoted attribute values are never touched;
 *  - <script>, <style>, <pre> and <textarea> are copied byte for byte (their whitespace is significant or is code).
 *
 * Anything it does not fully understand (an unclosed <script>, a pattern-matching failure on a very large page) makes it
 * return the input unchanged rather than guess. It never reorders, adds or removes tags or attributes.
 */
final class HtmlMinifier
{
    private const MAX_BYTES = 3_000_000;
    private const RAW = ['script', 'style', 'pre', 'textarea'];

    /** Tags that start/end a block in rendering: whitespace touching them has no visible effect. */
    private const BLOCK = [
        'html', 'head', 'body', 'title', 'meta', 'link', 'base', 'script', 'style', 'noscript', 'template', 'div', 'p', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'colgroup', 'col', 'form', 'fieldset', 'legend', 'section', 'article', 'aside',
        'header', 'footer', 'nav', 'main', 'figure', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'br', 'details', 'summary', 'select',
        'option', 'optgroup', 'datalist', 'pre', 'address', 'blockquote', '!doctype',
    ];

    private const TOKEN = '~<!--.*?-->|<(script|style|pre|textarea)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>|<!?/?[a-zA-Z][^\s>/]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~is';

    public static function minify(string $html): string
    {
        $len = strlen($html);
        if ($len < 64 || $len > self::MAX_BYTES) {
            return $html;
        }
        $found = @preg_match_all(self::TOKEN, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($found === false) {
            return $html;   // pattern-matching limit hit on an unusually large or odd page: leave it alone
        }

        /** @var list<array{0:string,1:string,2:bool}> $tokens [kind (text|tag|raw), value, isBlockBoundary] */
        $tokens = [];
        $pos = 0;
        foreach ($m as $match) {
            [$text, $offset] = $match[0];
            if ($offset > $pos) {
                self::addText($tokens, substr($html, $pos, $offset - $pos));
            }
            $pos = $offset + strlen($text);

            if (str_starts_with($text, '<!--')) {
                if (str_starts_with($text, '<!--[if') || str_contains($text, '<![endif]') || str_starts_with($text, '<!--<!')) {
                    $tokens[] = ['raw', $text, false];
                }
                continue;   // an ordinary comment simply disappears
            }
            if (isset($match[1]) && $match[1][0] !== '') {   // <script>…</script>, <pre>, <style>, <textarea> — verbatim
                $tokens[] = ['raw', $text, in_array(strtolower($match[1][0]), self::BLOCK, true)];
                continue;
            }

            $name = strtolower((string) preg_replace('~^<[!/]?([a-zA-Z0-9]*).*$~s', '$1', $text));
            if (str_starts_with($text, '<!') && strtolower(substr($text, 0, 9)) === '<!doctype') {
                $name = '!doctype';
            }
            if (in_array($name, self::RAW, true) && $text[1] !== '/') {
                return $html;   // an opening <script>/<pre>… with no closing tag: not well-formed enough to touch
            }
            $tokens[] = ['tag', self::tag($text), in_array($name, self::BLOCK, true)];
        }
        if ($pos < $len) {
            self::addText($tokens, substr($html, $pos));
        }

        return self::assemble($tokens);
    }

    /** @param list<array{0:string,1:string,2:bool}> $tokens */
    private static function addText(array &$tokens, string $text): void
    {
        $last = count($tokens) - 1;
        if ($last >= 0 && $tokens[$last][0] === 'text') {
            $tokens[$last][1] .= $text;   // a removed comment left two text runs side by side
        } else {
            $tokens[] = ['text', $text, false];
        }
    }

    /** @param list<array{0:string,1:string,2:bool}> $tokens */
    private static function assemble(array $tokens): string
    {
        $out = '';
        $n = count($tokens);
        foreach ($tokens as $i => [$kind, $value]) {
            if ($kind !== 'text') {
                $out .= $value;
                continue;
            }
            $value = (string) preg_replace('/[ \t\r\n\f]+/', ' ', $value);
            $beforeBlock = $i === 0 || $tokens[$i - 1][2];
            $afterBlock = $i === $n - 1 || $tokens[$i + 1][2];
            if ($beforeBlock) {
                $value = ltrim($value, ' ');
            }
            if ($afterBlock) {
                $value = rtrim($value, ' ');
            }
            $out .= $value;
        }

        return $out;
    }

    /** Collapse the whitespace between attributes; quoted values are copied exactly. */
    private static function tag(string $tag): string
    {
        $out = '';
        $quote = '';
        $space = false;
        $len = strlen($tag);
        for ($i = 0; $i < $len; $i++) {
            $c = $tag[$i];
            if ($quote !== '') {
                $out .= $c;
                if ($c === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n" || $c === "\f") {
                $space = true;
                continue;
            }
            if ($space) {
                // one space between tokens, none right before the closing bracket
                if ($c !== '>') {
                    $out .= ' ';
                }
                $space = false;
            }
            $out .= $c;
        }

        return $out;
    }
}
