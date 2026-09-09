<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DB-backed fixed-window rate limiter (`rate_limits` table). No Redis.
 *
 * A window row is `(bucket_key, window_started, hits)`. On each hit we
 * atomically upsert; if the stored window has expired we reset it. Cleanup of
 * stale rows is done by cron/cleanup.php.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Db $db,
        private readonly string $table = 'rate_limits',
    ) {
    }

    /**
     * Register a hit. Returns true if the caller is now OVER the limit
     * (i.e. this request should be rejected).
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        return $this->hit($key, $windowSeconds) > $maxAttempts;
    }

    /** Register a hit and return the current count within the window. */
    public function hit(string $key, int $windowSeconds): int
    {
        $now = time();
        $key = $this->normalize($key);

        // Native prepared statements do not allow a named placeholder to repeat,
        // so every occurrence gets its own name.
        $this->db->affectingStatement(
            "INSERT INTO `{$this->table}` (bucket_key, window_started, hits)
             VALUES (:k, FROM_UNIXTIME(:ts_insert), 1)
             ON DUPLICATE KEY UPDATE
                hits = IF(UNIX_TIMESTAMP(window_started) + :win_h < :now_h, 1, hits + 1),
                window_started = IF(UNIX_TIMESTAMP(window_started) + :win_w < :now_w, FROM_UNIXTIME(:ts_reset), window_started)",
            [
                'k' => $key,
                'ts_insert' => $now, 'ts_reset' => $now,
                'win_h' => $windowSeconds, 'win_w' => $windowSeconds,
                'now_h' => $now, 'now_w' => $now,
            ],
        );

        return (int) $this->db->selectValue(
            "SELECT hits FROM `{$this->table}` WHERE bucket_key = :k",
            ['k' => $key],
            0,
        );
    }

    public function attempts(string $key): int
    {
        return (int) $this->db->selectValue(
            "SELECT hits FROM `{$this->table}` WHERE bucket_key = :k",
            ['k' => $this->normalize($key)],
            0,
        );
    }

    /** Seconds until the current window resets (0 if none / already expired). */
    public function availableIn(string $key, int $windowSeconds): int
    {
        $started = $this->db->selectValue(
            "SELECT UNIX_TIMESTAMP(window_started) FROM `{$this->table}` WHERE bucket_key = :k",
            ['k' => $this->normalize($key)],
        );

        if ($started === null) {
            return 0;
        }

        return max(0, (int) $started + $windowSeconds - time());
    }

    public function clear(string $key): void
    {
        $this->db->affectingStatement(
            "DELETE FROM `{$this->table}` WHERE bucket_key = :k",
            ['k' => $this->normalize($key)],
        );
    }

    public function gc(int $olderThanSeconds = 86400): int
    {
        return $this->db->affectingStatement(
            "DELETE FROM `{$this->table}` WHERE window_started < FROM_UNIXTIME(:cutoff)",
            ['cutoff' => time() - $olderThanSeconds],
        );
    }

    private function normalize(string $key): string
    {
        // Keep keys bounded and predictable; hash long/odd inputs.
        return strlen($key) <= 150 && preg_match('/^[A-Za-z0-9:._\-@]+$/', $key)
            ? $key
            : 'h:' . hash('sha256', $key);
    }
}
