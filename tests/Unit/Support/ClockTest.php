<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Clock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function test_freeze_and_utc_string(): void
    {
        $clock = new Clock('Asia/Kolkata');
        $clock->freeze('2026-01-15 20:30:00');
        self::assertSame('2026-01-15 20:30:00', $clock->utcString());
        self::assertSame(strtotime('2026-01-15 20:30:00 UTC'), $clock->timestamp());
    }

    public function test_today_uses_business_timezone(): void
    {
        $clock = new Clock('Asia/Kolkata'); // +5:30
        // 20:00 UTC on the 15th is 01:30 on the 16th in Kolkata.
        $clock->freeze('2026-01-15 20:00:00');
        self::assertSame('2026-01-16', $clock->today());
    }

    public function test_days_until(): void
    {
        $clock = new Clock('Asia/Kolkata');
        $clock->freeze('2026-03-10 06:00:00');
        self::assertSame(0, $clock->daysUntil('2026-03-10'));
        self::assertSame(5, $clock->daysUntil('2026-03-15'));
        self::assertSame(-3, $clock->daysUntil('2026-03-07'));
    }

    public function test_unfreeze_returns_to_wall_clock(): void
    {
        $clock = new Clock('UTC');
        $clock->freeze('2000-01-01 00:00:00');
        $clock->unfreeze();
        self::assertGreaterThan(strtotime('2025-01-01'), $clock->timestamp());
    }
}
