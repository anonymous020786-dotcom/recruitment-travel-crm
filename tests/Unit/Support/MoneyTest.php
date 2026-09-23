<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return array<string,array{0:string|int|float,1:int}> */
    public static function minorCases(): array
    {
        return [
            'whole'              => ['1250', 125000],
            'one decimal'        => ['1250.5', 125050],
            'two decimals'       => ['1250.50', 125050],
            'int'                => [7, 700],
            'float'              => [19.99, 1999],
            'rounds half up'     => ['10.005', 1001],
            'rounds down'        => ['10.004', 1000],
            'long fraction'      => ['0.999', 100],
            'zero'               => ['0', 0],
            'negative'           => ['-5.25', -525],
            'large'              => ['999999999999.99', 99999999999999],
        ];
    }

    #[DataProvider('minorCases')]
    public function test_to_minor(string|int|float $in, int $expected): void
    {
        self::assertSame($expected, Money::toMinor($in));
    }

    public function test_from_minor_round_trips(): void
    {
        foreach ([0, 1, 9, 10, 99, 100, 101, 125050, 99999999999999, -1, -525] as $minor) {
            self::assertSame($minor, Money::toMinor(Money::fromMinor($minor)));
        }
        self::assertSame('0.05', Money::fromMinor(5));
        self::assertSame('1250.50', Money::fromMinor(125050));
        self::assertSame('-5.25', Money::fromMinor(-525));
    }

    public function test_line_totals_are_exact_and_round_half_up(): void
    {
        self::assertSame(9999, Money::lineTotalMinor(300, 3333), '3 × 33.33');
        self::assertSame(1000, Money::lineTotalMinor(50, 1999), '0.5 × 19.99 = 9.995 → 10.00');
        self::assertSame(0, Money::lineTotalMinor(1, 1), '0.01 × 0.01 rounds to 0');
        self::assertSame(45000_00, Money::lineTotalMinor(100, 45000_00));
    }

    public function test_the_classic_float_trap_does_not_bite(): void
    {
        // 0.1 + 0.2 != 0.3 in floats; in minor units it is exact.
        self::assertSame(Money::toMinor('0.3'), Money::toMinor('0.1') + Money::toMinor('0.2'));
        self::assertSame('1.10', Money::fromMinor(Money::toMinor('1.10')));
    }

    public function test_rejects_non_numeric_input(): void
    {
        foreach (['', 'abc', '1,250.00', '1e5', '12.3.4'] as $bad) {
            try {
                Money::toMinor($bad);
                self::fail("accepted: {$bad}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
