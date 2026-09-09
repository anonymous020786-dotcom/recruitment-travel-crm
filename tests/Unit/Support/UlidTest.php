<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function test_shape(): void
    {
        $ulid = Ulid::generate();
        self::assertSame(26, strlen($ulid));
        self::assertTrue(Ulid::isValid($ulid));
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid);
    }

    public function test_rejects_invalid(): void
    {
        self::assertFalse(Ulid::isValid('too-short'));
        self::assertFalse(Ulid::isValid(str_repeat('I', 26))); // I not in Crockford
    }

    public function test_monotonic_within_same_millisecond(): void
    {
        $ts = 1_700_000_000_000;
        $a = Ulid::generate($ts);
        $b = Ulid::generate($ts);
        $c = Ulid::generate($ts);
        self::assertTrue($a < $b && $b < $c, "expected {$a} < {$b} < {$c}");
    }

    public function test_lexicographic_time_ordering(): void
    {
        $early = Ulid::generate(1_600_000_000_000);
        $late = Ulid::generate(1_700_000_000_000);
        self::assertTrue($early < $late);
    }

    public function test_timestamp_roundtrip(): void
    {
        $ts = 1_699_999_999_000;
        self::assertSame($ts, Ulid::timestamp(Ulid::generate($ts)));
    }

    public function test_uniqueness_bulk(): void
    {
        $set = [];
        for ($i = 0; $i < 5000; $i++) {
            $set[Ulid::generate()] = true;
        }
        self::assertCount(5000, $set);
    }
}
