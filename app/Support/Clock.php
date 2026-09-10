<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The single source of "now". Storage is always UTC; display and "today"
 * comparisons (due dates, expiry windows) use the configured business timezone.
 * Inject this instead of calling `time()` / `new DateTime()` directly so tests
 * can freeze time.
 */
final class Clock
{
    private ?DateTimeImmutable $frozen = null;

    public function __construct(private readonly string $displayTimezone = 'UTC')
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** UTC 'Y-m-d H:i:s' — for writing to the database. */
    public function utcString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function timestamp(): int
    {
        return $this->now()->getTimestamp();
    }

    /** The business-timezone calendar date, e.g. for "due today" / "overdue". */
    public function today(): string
    {
        return $this->now()->setTimezone(new DateTimeZone($this->displayTimezone))->format('Y-m-d');
    }

    public function inDisplayTz(DateTimeImmutable $utc): DateTimeImmutable
    {
        return $utc->setTimezone(new DateTimeZone($this->displayTimezone));
    }

    /** Days from today (business tz) until $date ('Y-m-d'); negative = past. */
    public function daysUntil(string $date): int
    {
        $today = new DateTimeImmutable($this->today(), new DateTimeZone($this->displayTimezone));
        $target = new DateTimeImmutable($date, new DateTimeZone($this->displayTimezone));

        return (int) $today->diff($target)->format('%r%a');
    }

    // ---- test helpers -------------------------------------------------

    public function freeze(DateTimeImmutable|string $at): void
    {
        $this->frozen = $at instanceof DateTimeImmutable
            ? $at->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    public function unfreeze(): void
    {
        $this->frozen = null;
    }
}
