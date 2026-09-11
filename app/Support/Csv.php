<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV writing with formula-injection protection ("CSV injection" /
 * CWE-1236): a cell that starts with `=`, `+`, `-`, `@`, a tab, or a carriage
 * return can be interpreted as a formula by Excel/Sheets when the file is
 * later opened. Every cell we write is neutralised with a leading apostrophe,
 * the standard mitigation, regardless of source (a lead's name or notes are
 * free text someone typed — never trust it into a spreadsheet unescaped).
 */
final class Csv
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function sanitizeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'" . $value;
            }
        }

        return $value;
    }

    /** @param resource $handle @param list<mixed> $row */
    public static function writeRow($handle, array $row): void
    {
        fputcsv($handle, array_map([self::class, 'sanitizeCell'], $row));
    }
}
