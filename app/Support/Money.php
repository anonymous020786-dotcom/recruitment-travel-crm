<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Exact money arithmetic on integer minor units (paise / cents), so the ledger
 * never accumulates float error and does not depend on bcmath being installed.
 * Amounts travel as 2-decimal strings ("1250.50"); maths happens on ints.
 *
 * Callers cap inputs (invoice line quantity ≤ 100 000, unit price ≤ 99 999 999)
 * so no product can overflow a 64-bit int.
 */
final class Money
{
    /** "1250.5" / 1250.5 / 1250 → 125050. Rounds half up to 2 decimals. */
    public static function toMinor(string|int|float $amount): int
    {
        $s = trim((string) $amount);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new \InvalidArgumentException("Not a decimal amount: {$s}");
        }
        $negative = $s[0] === '-';
        [$whole, $frac] = array_pad(explode('.', ltrim($s, '-'), 2), 2, '');
        $cents = (int) str_pad(substr($frac, 0, 2), 2, '0');
        $minor = (int) $whole * 100 + $cents;
        if (strlen($frac) > 2 && (int) $frac[2] >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }

    /** 125050 → "1250.50" */
    public static function fromMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** quantity (2 dp) × unit price → line total, all in minor units, rounded half up. */
    public static function lineTotalMinor(int $quantityMinor, int $unitMinor): int
    {
        return intdiv($quantityMinor * $unitMinor + 50, 100);
    }
}
