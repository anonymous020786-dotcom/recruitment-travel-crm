<?php

declare(strict_types=1);

namespace App\Support;

/** URL-safe slugs for public pages. */
final class Slug
{
    public static function make(string $text, int $maxLength = 120): string
    {
        $text = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
        $text = strtolower($text);
        $text = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
        $text = substr($text, 0, $maxLength);

        return trim($text, '-') !== '' ? trim($text, '-') : 'job';
    }
}
