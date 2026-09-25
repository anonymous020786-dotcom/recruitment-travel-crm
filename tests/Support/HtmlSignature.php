<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A rendering-relevant fingerprint of an HTML document: every element with its attributes, and every piece of text, with
 * whitespace differences that a browser would not show ignored (except inside <pre>, <textarea>, <script> and <style>,
 * where it is compared exactly). Two documents with the same signature render the same.
 */
final class HtmlSignature
{
    /** @return list<string> */
    public static function of(string $html): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $out = [];
        $walk = static function (\DOMNode $node, int $depth, bool $raw) use (&$walk, &$out): void {
            if ($node instanceof \DOMElement) {
                $attrs = [];
                foreach ($node->attributes as $a) {
                    $attrs[$a->name] = $a->value;
                }
                ksort($attrs);
                $out[] = str_repeat(' ', $depth) . '<' . $node->tagName . ' ' . json_encode($attrs, JSON_UNESCAPED_UNICODE);
                $raw = $raw || in_array($node->tagName, ['pre', 'textarea', 'script', 'style'], true);
                foreach ($node->childNodes as $child) {
                    $walk($child, $depth + 1, $raw);
                }
            } elseif ($node instanceof \DOMText) {
                $text = $raw ? $node->wholeText : trim((string) preg_replace('/\s+/', ' ', $node->wholeText));
                if ($text !== '') {
                    $out[] = str_repeat(' ', $depth) . '"' . $text . '"';
                }
            }
        };
        foreach ($doc->childNodes as $child) {
            $walk($child, 0, false);
        }

        // adjacent text runs can be split differently by the parser; merge consecutive text lines at the same depth
        $merged = [];
        foreach ($out as $line) {
            $last = count($merged) - 1;
            if ($last >= 0 && str_contains($line, '"') && !str_contains($line, '<') && !str_contains($merged[$last], '<')
                && strspn($line, ' ') === strspn($merged[$last], ' ')) {
                $merged[$last] = rtrim($merged[$last], '"') . ' ' . ltrim(substr($line, strspn($line, ' ')), '"');
                continue;
            }
            $merged[] = $line;
        }

        return $merged;
    }
}
