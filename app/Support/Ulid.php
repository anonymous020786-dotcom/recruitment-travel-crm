<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ULID generator (Crockford base32, 26 chars): 48-bit millisecond timestamp +
 * 80-bit randomness. Lexicographically sortable, URL-safe, no hyphens.
 *
 * Used for every `public_id` exposed in URLs and for request ids. The numeric
 * primary key is never exposed.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford (no I,L,O,U)

    private static ?string $lastTime = null;
    /** @var list<int> */
    private static array $lastRand = [];

    public static function generate(?int $timestampMs = null): string
    {
        $timestampMs ??= (int) (microtime(true) * 1000);

        $timeChars = self::encodeTime($timestampMs);

        // Monotonic within the same millisecond: increment the random part.
        if (self::$lastTime === $timeChars && self::$lastRand !== []) {
            self::$lastRand = self::incrementRandom(self::$lastRand);
        } else {
            self::$lastRand = self::randomBytesAsQuintets();
        }
        self::$lastTime = $timeChars;

        return $timeChars . self::encodeQuintets(self::$lastRand);
    }

    public static function isValid(string $ulid): bool
    {
        return strlen($ulid) === 26
            && strspn(strtoupper($ulid), self::ALPHABET) === 26;
    }

    public static function timestamp(string $ulid): ?int
    {
        if (!self::isValid($ulid)) {
            return null;
        }
        $time = substr(strtoupper($ulid), 0, 10);
        $value = 0;
        foreach (str_split($time) as $char) {
            $value = $value * 32 + strpos(self::ALPHABET, $char);
        }

        return $value;
    }

    private static function encodeTime(int $ms): string
    {
        $chars = '';
        for ($i = 9; $i >= 0; $i--) {
            $chars = self::ALPHABET[$ms % 32] . $chars;
            $ms = intdiv($ms, 32);
        }

        return $chars;
    }

    /** @return list<int> 16 quintets (5-bit groups) of randomness */
    private static function randomBytesAsQuintets(): array
    {
        $bytes = random_bytes(10); // 80 bits
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return array_map('bindec', str_split($bits, 5));
    }

    /** @param list<int> $quintets @return string */
    private static function encodeQuintets(array $quintets): string
    {
        return implode('', array_map(static fn (int $q) => self::ALPHABET[$q & 31], $quintets));
    }

    /** @param list<int> $quintets @return list<int> */
    private static function incrementRandom(array $quintets): array
    {
        for ($i = count($quintets) - 1; $i >= 0; $i--) {
            if ($quintets[$i] < 31) {
                $quintets[$i]++;

                return $quintets;
            }
            $quintets[$i] = 0;
        }

        // Overflow (astronomically unlikely) — reseed.
        return self::randomBytesAsQuintets();
    }
}
