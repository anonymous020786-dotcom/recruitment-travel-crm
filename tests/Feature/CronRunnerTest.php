<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\CronRunner;
use Tests\Support\DbTestCase;

final class CronRunnerTest extends DbTestCase
{
    private CronRunner $runner;
    private string $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new CronRunner($this->db, $this->app->get(\App\Support\Logger::class));
        $this->job = 'test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM cron_locks WHERE job_name = ?', [$this->job]);
        $this->db->affectingStatement('DELETE FROM cron_runs WHERE job_name = ?', [$this->job]);
    }

    public function test_successful_run_records_ledger_and_releases_lock(): void
    {
        $code = $this->runner->run($this->job, 60, fn () => 7);

        self::assertSame(0, $code);
        $run = $this->db->selectOne('SELECT status, items_processed, finished_at FROM cron_runs WHERE job_name = ?', [$this->job]);
        self::assertSame('success', $run['status']);
        self::assertSame(7, (int) $run['items_processed']);
        self::assertNotNull($run['finished_at']);
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM cron_locks WHERE job_name = ?', [$this->job]));
    }

    public function test_failure_is_recorded_and_lock_released(): void
    {
        $code = $this->runner->run($this->job, 60, function (): void {
            throw new \RuntimeException('boom');
        });

        self::assertSame(1, $code);
        self::assertSame('failed', $this->db->selectValue('SELECT status FROM cron_runs WHERE job_name = ?', [$this->job]));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM cron_locks WHERE job_name = ?', [$this->job]));
    }

    public function test_overlapping_run_is_skipped(): void
    {
        // Simulate a still-running instance holding a fresh lock.
        $this->db->affectingStatement(
            'INSERT INTO cron_locks (job_name, locked_at, locked_by, expires_at)
             VALUES (:j, UTC_TIMESTAMP(), :by, (UTC_TIMESTAMP() + INTERVAL 300 SECOND))',
            ['j' => $this->job, 'by' => 'other:1'],
        );

        $ran = false;
        $code = $this->runner->run($this->job, 60, function () use (&$ran) { $ran = true; });

        self::assertSame(2, $code);
        self::assertFalse($ran);
    }

    public function test_stale_lock_is_reclaimed(): void
    {
        $this->db->affectingStatement(
            'INSERT INTO cron_locks (job_name, locked_at, locked_by, expires_at)
             VALUES (:j, (UTC_TIMESTAMP() - INTERVAL 1 HOUR), :by, (UTC_TIMESTAMP() - INTERVAL 30 MINUTE))',
            ['j' => $this->job, 'by' => 'dead:1'],
        );

        $ran = false;
        $code = $this->runner->run($this->job, 60, function () use (&$ran) { $ran = true; });

        self::assertSame(0, $code);
        self::assertTrue($ran);
    }
}
