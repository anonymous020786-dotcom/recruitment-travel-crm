<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Cbor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CborTest extends TestCase
{
    #[DataProvider('vectors')]
    public function test_decodes_rfc_examples(string $hex, mixed $expected): void
    {
        self::assertSame($expected, Cbor::decode((string) hex2bin($hex)));
    }

    /** @return iterable<string,array{0:string,1:mixed}> */
    public static function vectors(): iterable
    {
        yield 'zero'            => ['00', 0];
        yield 'ten'             => ['0a', 10];
        yield 'uint8'           => ['1818', 24];
        yield 'uint16'          => ['1903e8', 1000];
        yield 'negative one'    => ['20', -1];
        yield 'negative 500'    => ['3901f3', -500];
        yield 'byte string'     => ['43010203', "\x01\x02\x03"];
        yield 'text string'     => ['6449455446', 'IETF'];
        yield 'array'           => ['83010203', [1, 2, 3]];
        yield 'map int keys'    => ['a201020304', [1 => 2, 3 => 4]];
        yield 'map text keys'   => ['a1626964182a', ['id' => 42]];
        yield 'false'           => ['f4', false];
        yield 'true'            => ['f5', true];
        yield 'null'            => ['f6', null];
        yield 'nested'          => ['a1616183010203', ['a' => [1, 2, 3]]];
    }

    public function test_decode_first_reports_consumed_bytes(): void
    {
        $data = (string) hex2bin('a10102') . 'TRAILING';
        [$value, $offset] = Cbor::decodeFirst($data);

        self::assertSame([1 => 2], $value);
        self::assertSame(3, $offset);
    }

    public function test_rejects_trailing_bytes_on_decode(): void
    {
        $this->expectException(\RuntimeException::class);
        Cbor::decode((string) hex2bin('00') . 'x');
    }

    public function test_rejects_indefinite_length(): void
    {
        $this->expectException(\RuntimeException::class);
        Cbor::decode((string) hex2bin('5f42010243030405ff'));
    }

    public function test_rejects_truncated_string(): void
    {
        $this->expectException(\RuntimeException::class);
        Cbor::decode((string) hex2bin('43ab')); // says 3 bytes, has 1
    }
}
