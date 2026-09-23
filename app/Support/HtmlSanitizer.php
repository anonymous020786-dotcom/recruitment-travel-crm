<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Allowlist sanitizer for the small rich-text fields stored as HTML (job
 * descriptions shown on the public site). Only a handful of formatting tags
 * survive and every attribute is dropped, so there is no vector for event
 * handlers, `javascript:` URLs or inline styles — links and images are not
 * allowed at all. Everything is re-escaped text otherwise.
 */
final class HtmlSanitizer
{
    private const ALLOWED = '<p><br><ul><ol><li><strong><em><b><i><h3><h4>';

    public static function clean(string $html): string
    {
        // Remove script/style bodies entirely — strip_tags alone would keep their text.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = strip_tags($html, self::ALLOWED);
        // Drop every attribute from the tags that remain.
        $html = preg_replace('#<(/?)([a-z0-9]+)\b[^>]*?(/?)>#i', '<$1$2$3>', $html) ?? '';

        return trim($html);
    }

    /** Plain-text (textarea) input → safe paragraph HTML. */
    public static function fromPlainText(string $text): string
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            return '';
        }
        $paras = preg_split('/\n{2,}/', $text) ?: [];

        return implode('', array_map(
            static fn (string $p): string => '<p>' . str_replace("\n", '<br>', htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
            $paras,
        ));
    }
}
