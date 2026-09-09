<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\RateLimiter;
use Tests\Support\DbTestCase;

final class RateLimiterTest extends DbTestCase
{
    private RateLimiter $limiter;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new RateLimiter($this->db);
        $this->key = 'test:' . bin2hex(random_bytes(6));
        $this->limiter->clear($this->key);
    }

    protected function tearDown(): void
    {
        $this->limiter->clear($this->key);
    }

    public function test_counts_hits_within_window(): void
    {
        self::assertSame(1, $this->limiter->hit($this->key, 60));
        self::assertSame(2, $this->limiter->hit($this->key, 60));
        self::assertSame(3, $this->limiter->hit($this->key, 60));
        self::assertSame(3, $this->limiter->attempts($this->key));
    }

    public function test_too_many_attempts_flips_after_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->limiter->tooManyAttempts($this->key, 5, 60), "hit {$i} should be allowed");
        }
        self::assertTrue($this->limiter->tooManyAttempts($this->key, 5, 60), '6th hit is over the limit');
    }

    public function test_window_resets_after_expiry(): void
    {
        $this->limiter->hit($this->key, 1);
        $this->limiter->hit($this->key, 1);
        // Force the stored window into the past.
        $this->db->affectingStatement(
            'UPDATE rate_limits SET window_started = FROM_UNIXTIME(?) WHERE bucket_key = ?',
            [time() - 10, $this->key],
        );
        self::assertSame(1, $this->limiter->hit($this->key, 1), 'expired window resets to 1');
    }

    public function test_available_in_counts_down(): void
    {
        $this->limiter->hit($this->key, 120);
        $remaining = $this->limiter->availableIn($this->key, 120);
        self::assertGreaterThan(100, $remaining);
        self::assertLessThanOrEqual(120, $remaining);
    }

    public function test_clear(): void
    {
        $this->limiter->hit($this->key, 60);
        $this->limiter->clear($this->key);
        self::assertSame(0, $this->limiter->attempts($this->key));
    }
}
