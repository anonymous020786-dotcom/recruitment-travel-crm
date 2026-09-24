<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CronSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronScheduleTest extends TestCase
{
    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    /** @return array<string,array{0:string,1:string,2:bool}> */
    public static function matchCases(): array
    {
        return [
            'every minute'                 => ['* * * * *', '2026-09-24 03:17:00', true],
            'exact time'                   => ['0 9 * * *', '2026-09-24 09:00:00', true],
            'exact time, wrong minute'     => ['0 9 * * *', '2026-09-24 09:01:00', false],
            'step of 15'                   => ['*/15 * * * *', '2026-09-24 03:45:00', true],
            'step of 15, off the grid'     => ['*/15 * * * *', '2026-09-24 03:46:00', false],
            'hourly'                       => ['0 * * * *', '2026-09-24 18:00:00', true],
            'list of minutes'              => ['5,35 * * * *', '2026-09-24 12:35:00', true],
            'range of hours'               => ['0 9-17 * * *', '2026-09-24 17:00:00', true],
            'range of hours, outside'      => ['0 9-17 * * *', '2026-09-24 18:00:00', false],
            'range with step'              => ['10-30/10 * * * *', '2026-09-24 01:20:00', true],
            'range with step, off'         => ['10-30/10 * * * *', '2026-09-24 01:25:00', false],
            'month restricted'             => ['0 0 1 1 *', '2026-01-01 00:00:00', true],
            'month restricted, wrong'      => ['0 0 1 1 *', '2026-02-01 00:00:00', false],
            'weekday only (Thu = 4)'       => ['0 8 * * 4', '2026-09-24 08:00:00', true],
            'weekday only, wrong day'      => ['0 8 * * 4', '2026-09-25 08:00:00', false],
            'Sunday as 0'                  => ['0 8 * * 0', '2026-09-27 08:00:00', true],
            'Sunday as 7'                  => ['0 8 * * 7', '2026-09-27 08:00:00', true],
            'weekdays 1-5, Saturday'       => ['0 8 * * 1-5', '2026-09-26 08:00:00', false],
            'dom AND dow both set: dom'    => ['0 0 13 * 5', '2026-09-13 00:00:00', true],   // the 13th (a Sunday) — OR semantics
            'dom AND dow both set: dow'    => ['0 0 13 * 5', '2026-09-18 00:00:00', true],   // a Friday that is not the 13th
            'dom AND dow both set: neither' => ['0 0 13 * 5', '2026-09-17 00:00:00', false],
            'time zones are normalised'    => ['0 9 * * *', '2026-09-24 14:30:00+05:30', true],
        ];
    }

    #[DataProvider('matchCases')]
    public function test_matching(string $expr, string $when, bool $expected): void
    {
        self::assertSame($expected, CronSchedule::parse($expr)->matches(self::at($when)), "{$expr} @ {$when}");
    }

    public function test_last_due_is_the_latest_scheduled_minute_not_after_now(): void
    {
        $daily = CronSchedule::parse('0 9 * * *');
        self::assertSame('2026-09-24 09:00:00', $daily->lastDueAt(self::at('2026-09-24 09:00:30'))?->format('Y-m-d H:i:s'), 'the minute itself counts');
        self::assertSame('2026-09-24 09:00:00', $daily->lastDueAt(self::at('2026-09-24 17:45:12'))?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-23 09:00:00', $daily->lastDueAt(self::at('2026-09-24 08:59:59'))?->format('Y-m-d H:i:s'), 'before today\'s slot → yesterday\'s');

        $quarter = CronSchedule::parse('*/15 * * * *');
        self::assertSame('2026-09-24 03:45:00', $quarter->lastDueAt(self::at('2026-09-24 03:59:00'))?->format('Y-m-d H:i:s'));

        $weekly = CronSchedule::parse('30 6 * * 1');   // Mondays
        self::assertSame('2026-09-21 06:30:00', $weekly->lastDueAt(self::at('2026-09-24 12:00:00'))?->format('Y-m-d H:i:s'));
    }

    public function test_a_schedule_that_has_not_fired_within_the_lookback_gives_null(): void
    {
        self::assertNull(CronSchedule::parse('0 0 1 1 *')->lastDueAt(self::at('2026-09-24 12:00:00')), 'yearly job, 8-day lookback');
        self::assertNotNull(CronSchedule::parse('0 0 1 1 *')->lastDueAt(self::at('2026-09-24 12:00:00'), 300 * 1440));
    }

    /** @return array<string,array{0:string}> */
    public static function badExpressions(): array
    {
        return [
            'too few fields'   => ['* * * *'],
            'too many fields'  => ['* * * * * *'],
            'empty'            => [''],
            'minute too big'   => ['60 * * * *'],
            'hour too big'     => ['0 24 * * *'],
            'dom zero'         => ['0 0 0 * *'],
            'month 13'         => ['0 0 1 13 *'],
            'dow 8'            => ['0 0 * * 8'],
            'reversed range'   => ['30-10 * * * *'],
            'zero step'        => ['*/0 * * * *'],
            'letters'          => ['0 0 * * MON'],
            'macro'            => ['@daily'],
            'empty list item'  => ['1,,2 * * * *'],
            'negative'         => ['-5 * * * *'],
        ];
    }

    #[DataProvider('badExpressions')]
    public function test_bad_expressions_are_rejected(string $expr): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CronSchedule::parse($expr);
    }

    public function test_every_schedule_in_the_real_registry_parses_and_fires_at_least_weekly(): void
    {
        $jobs = (require dirname(__DIR__, 3) . '/config/cron.php')['jobs'];
        self::assertNotEmpty($jobs);
        $now = self::at('2026-09-24 12:34:00');

        foreach ($jobs as $name => $def) {
            $s = CronSchedule::parse($def['schedule']);
            self::assertNotNull($s->lastDueAt($now), "{$name} ({$def['schedule']}) never fires");
        }
    }
}
