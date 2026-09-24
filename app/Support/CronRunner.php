<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\QueryException;
use Closure;
use Throwable;

/**
 * Shared harness for cron/*.php: advisory lock (so a slow run never overlaps
 * itself), a `cron_runs` ledger entry, and structured logging. Every job is
 * safe to run more often than scheduled.
 */
final class CronRunner
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * End a cron script. Run on its own (`php cron/x.php`) the script exits with the job's code; when
     * `cron/dispatch.php` includes it in-process (CRON_IN_PROCESS) the code is returned so the dispatcher goes on.
     */
    public static function finish(int $code): int
    {
        if (\defined('CRON_IN_PROCESS')) {
            return $code;
        }
        exit($code);
    }

    /**
     * @param Closure(callable(int):void):(int|void) $work receives a progress
     *        callback; return (or report) the number of items processed.
     * @return int exit code (0 ok, 1 failed, 2 skipped because locked)
     */
    public function run(string $job, int $lockTtlSeconds, Closure $work): int
    {
        $lockedBy = gethostname() . ':' . getmypid();

        if (!$this->acquireLock($job, $lockTtlSeconds, $lockedBy)) {
            $this->logger->info('cron {job} skipped — already running', ['job' => $job]);

            return 2;
        }

        $runId = (int) $this->db->insertRow('cron_runs', [
            'job_name'   => $job,
            'started_at' => gmdate('Y-m-d H:i:s'), // UTC, like finished_at — the dispatcher compares these to schedule times
            'status'     => 'running',
        ]);

        $processed = 0;
        $status = 'success';
        $message = null;

        try {
            $result = $work(function (int $n) use (&$processed): void {
                $processed += $n;
            });
            if (is_int($result)) {
                $processed = $result;
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $message = mb_substr($e->getMessage(), 0, 500);
            $this->logger->critical('cron {job} failed: {message}', ['job' => $job, 'message' => $e->getMessage(), 'exception' => $e]);
        } finally {
            $this->db->affectingStatement(
                "UPDATE cron_runs SET finished_at = UTC_TIMESTAMP(), status = :s, items_processed = :n, message = :m WHERE id = :id",
                ['id' => $runId, 's' => $status, 'n' => $processed, 'm' => $message],
            );
            $this->releaseLock($job, $lockedBy);
        }

        $this->logger->info('cron {job} {status} ({n} processed)', ['job' => $job, 'status' => $status, 'n' => $processed]);

        return $status === 'success' ? 0 : 1;
    }

    private function acquireLock(string $job, int $ttl, string $lockedBy): bool
    {
        $this->db->affectingStatement(
            'DELETE FROM cron_locks WHERE job_name = :j AND expires_at < UTC_TIMESTAMP()',
            ['j' => $job],
        );

        try {
            $this->db->affectingStatement(
                'INSERT INTO cron_locks (job_name, locked_at, locked_by, expires_at)
                 VALUES (:j, UTC_TIMESTAMP(), :by, (UTC_TIMESTAMP() + INTERVAL :ttl SECOND))',
                ['j' => $job, 'by' => mb_substr($lockedBy, 0, 120), 'ttl' => $ttl],
            );

            return true;
        } catch (QueryException $e) {
            return !$e->isDuplicateKey() ? throw $e : false;
        }
    }

    private function releaseLock(string $job, string $lockedBy): void
    {
        $this->db->affectingStatement(
            'DELETE FROM cron_locks WHERE job_name = :j AND locked_by = :by',
            ['j' => $job, 'by' => mb_substr($lockedBy, 0, 120)],
        );
    }
}
