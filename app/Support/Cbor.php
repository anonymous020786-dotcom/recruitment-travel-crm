<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal CBOR decoder (RFC 8949) — just enough to parse the WebAuthn
 * structures we handle: the attestation object and COSE_Key maps.
 *
 * Definite-length items only. Indefinite-length encodings, and anything a
 * conformant authenticator would never emit, are rejected rather than guessed
 * at — this parser sits on an authentication path.
 */
final class Cbor
{
    /** Decode the single CBOR data item at the start of $data. */
    public static function decode(string $data): mixed
    {
        [$value, $offset] = self::decodeItem($data, 0);

        if ($offset !== strlen($data)) {
            throw new \RuntimeException('CBOR: trailing bytes after top-level item');
        }

        return $value;
    }

    /**
     * Decode one item and report how many bytes it consumed. Used where a CBOR
     * item is followed by non-CBOR trailing data (the raw authenticator data
     * embedded after a COSE key inside attested credential data).
     *
     * @return array{0: mixed, 1: int}
     */
    public static function decodeFirst(string $data): array
    {
        return self::decodeItem($data, 0);
    }

    /** @return array{0: mixed, 1: int} */
    private static function decodeItem(string $data, int $offset): array
    {
        if ($offset >= strlen($data)) {
            throw new \RuntimeException('CBOR: unexpected end of data');
        }

        $initial = ord($data[$offset]);
        $major = $initial >> 5;
        $minor = $initial & 0x1f;
        $offset++;

        if ($minor === 31) {
            throw new \RuntimeException('CBOR: indefinite-length items are not supported');
        }

        if ($major === 7) {
            return [
                match ($minor) {
                    20 => false,
                    21 => true,
                    22, 23 => null,
                    default => throw new \RuntimeException('CBOR: floats and simple values are not supported'),
                },
                $offset,
            ];
        }

        [$value, $offset] = self::readArgument($data, $offset, $minor);

        return match ($major) {
            0 => [$value, $offset],                 // unsigned integer
            1 => [-1 - $value, $offset],             // negative integer
            2, 3 => self::readString($data, $offset, $value), // byte / text string
            4 => self::readArray($data, $offset, $value),
            5 => self::readMap($data, $offset, $value),
            6 => self::decodeItem($data, $offset),  // tag: return the tagged item
            default => throw new \RuntimeException('CBOR: unknown major type'),
        };
    }

    /** @return array{0:int,1:int} the argument value and the new offset */
    private static function readArgument(string $data, int $offset, int $minor): array
    {
        if ($minor < 24) {
            return [$minor, $offset];
        }

        $lengths = [24 => 1, 25 => 2, 26 => 4, 27 => 8];
        if (!isset($lengths[$minor])) {
            throw new \RuntimeException('CBOR: reserved additional-information value');
        }

        $n = $lengths[$minor];
        if ($offset + $n > strlen($data)) {
            throw new \RuntimeException('CBOR: truncated argument');
        }

        $bytes = substr($data, $offset, $n);
        $offset += $n;

        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        if ($value < 0) {
            throw new \RuntimeException('CBOR: 64-bit value out of PHP integer range');
        }

        return [$value, $offset];
    }

    /** @return array{0:string,1:int} */
    private static function readString(string $data, int $offset, int $length): array
    {
        if ($offset + $length > strlen($data)) {
            throw new \RuntimeException('CBOR: string length exceeds data');
        }

        return [substr($data, $offset, $length), $offset + $length];
    }

    /** @return array{0:list<mixed>,1:int} */
    private static function readArray(string $data, int $offset, int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            [$item, $offset] = self::decodeItem($data, $offset);
            $items[] = $item;
        }

        return [$items, $offset];
    }

    /** @return array{0:array<int|string,mixed>,1:int} */
    private static function readMap(string $data, int $offset, int $count): array
    {
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            [$key, $offset] = self::decodeItem($data, $offset);
            [$value, $offset] = self::decodeItem($data, $offset);

            if (!is_int($key) && !is_string($key)) {
                throw new \RuntimeException('CBOR: unsupported map key type');
            }
            $map[$key] = $value;
        }

        return [$map, $offset];
    }
}
