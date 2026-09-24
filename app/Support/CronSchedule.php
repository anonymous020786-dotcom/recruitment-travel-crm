<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A standard five-field cron expression (minute hour day-of-month month day-of-week), evaluated in UTC.
 * Supports `*`, lists (`1,15`), ranges (`1-5`), steps (`*​/5`, `10-30/10`) and day-of-week 0–7 (both 0 and 7 are
 * Sunday). As in classic cron, when both day-of-month and day-of-week are restricted, a day matches if
 * EITHER does. Names (`MON`, `JAN`) and macros (`@daily`) are deliberately not supported: the registry is ours.
 */
final class CronSchedule
{
    /** @var array<int,array<int,true>> field index => allowed values */
    private array $sets;
    private bool $domRestricted;
    private bool $dowRestricted;

    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    private const LABELS = ['minute', 'hour', 'day of month', 'month', 'day of week'];

    private function __construct(public readonly string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("A cron expression needs 5 fields, got: '{$expression}'");
        }
        foreach ($parts as $i => $part) {
            $this->sets[$i] = $this->expand($part, self::RANGES[$i][0], self::RANGES[$i][1], self::LABELS[$i]);
        }
        // 7 is Sunday too
        if (isset($this->sets[4][7])) {
            $this->sets[4][0] = true;
            unset($this->sets[4][7]);
        }
        $this->domRestricted = $parts[2] !== '*';
        $this->dowRestricted = $parts[4] !== '*';
    }

    public static function parse(string $expression): self
    {
        return new self($expression);
    }

    public function matches(\DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new \DateTimeZone('UTC'));
        [$min, $hour, $dom, $mon, $dow] = array_map('intval', explode(' ', $at->format('i G j n w')));

        if (!isset($this->sets[0][$min], $this->sets[1][$hour], $this->sets[3][$mon])) {
            return false;
        }
        $domOk = isset($this->sets[2][$dom]);
        $dowOk = isset($this->sets[4][$dow]);

        return match (true) {
            $this->domRestricted && $this->dowRestricted => $domOk || $dowOk,
            $this->domRestricted                        => $domOk,
            $this->dowRestricted                        => $dowOk,
            default                                     => true,
        };
    }

    /**
     * The latest scheduled minute at or before `$now` (seconds dropped), or null if the schedule has not
     * fired within `$lookbackMinutes` (default 8 days — every job we run fires at least weekly).
     */
    public function lastDueAt(\DateTimeImmutable $now, int $lookbackMinutes = 8 * 1440): ?\DateTimeImmutable
    {
        $t = $now->setTimezone(new \DateTimeZone('UTC'))->setTime((int) $now->format('G'), (int) $now->format('i'), 0);

        for ($i = 0; $i <= $lookbackMinutes; $i++) {
            if ($this->matches($t)) {
                return $t;
            }
            $t = $t->modify('-1 minute');
        }

        return null;
    }

    /** @return array<int,true> */
    private function expand(string $field, int $min, int $max, string $label): array
    {
        $out = [];
        foreach (explode(',', $field) as $piece) {
            if ($piece === '') {
                throw new \InvalidArgumentException("Empty {$label} field in '{$this->expression}'");
            }
            $step = 1;
            if (str_contains($piece, '/')) {
                [$piece, $stepRaw] = explode('/', $piece, 2);
                if (!ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                    throw new \InvalidArgumentException("Bad step in {$label}: '{$field}'");
                }
                $step = (int) $stepRaw;
            }

            if ($piece === '*') {
                [$lo, $hi] = [$min, $max === 7 ? 6 : $max];
            } elseif (preg_match('/^(\d+)-(\d+)$/', $piece, $m)) {
                [$lo, $hi] = [(int) $m[1], (int) $m[2]];
            } elseif (ctype_digit($piece)) {
                [$lo, $hi] = [(int) $piece, str_contains($field, '/') ? $max : (int) $piece];
            } else {
                throw new \InvalidArgumentException("Bad {$label} field: '{$field}'");
            }
            if ($lo < $min || $hi > $max || $lo > $hi) {
                throw new \InvalidArgumentException("{$label} out of range ({$min}-{$max}): '{$field}'");
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                $out[$v] = true;
            }
        }

        return $out;
    }
}
