<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Throwable;

/**
 * Single-entry cron: for hosting plans that allow only one cron line. Run `cron/dispatch.php` every 5 minutes
 * and it decides, from each job's schedule in `config/cron.php` and the `cron_runs` ledger, which jobs are due
 * and runs them one after another inside a time budget.
 *
 * A job is *due* when its schedule has fired since the job last started (any outcome). So a missed slot is
 * caught up on the next tick, a slow tick never runs a job twice for one slot, and a job that failed is retried
 * at its next scheduled time rather than every five minutes. Each job still takes its own lock and writes its
 * own `cron_runs` row (CronRunner), so running the dispatcher and a real cron line side by side is harmless.
 */
final class CronDispatcher
{
    /** Runner code meaning "this job's script does not exist" (not a failure, not a run). */
    public const MISSING = 4;

    /**
     * @param array<string,array<string,mixed>> $jobs job name => definition (`schedule`, optional `enabled`)
     * @param Closure(string,array<string,mixed>):int $runner runs one job, returns its exit code (0 ok, 2 already running)
     */
    public function __construct(
        private readonly Db $db,
        private readonly array $jobs,
        private readonly Closure $runner,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * @return list<array{job:string,due_at:string,last_run:?string}> jobs due at `$now`, in registry order
     */
    public function due(\DateTimeImmutable $now): array
    {
        $last = $this->lastRuns();
        $out = [];

        foreach ($this->jobs as $name => $def) {
            if (($def['enabled'] ?? true) === false || !isset($def['schedule'])) {
                continue;
            }
            try {
                $dueAt = CronSchedule::parse((string) $def['schedule'])->lastDueAt($now);
            } catch (\InvalidArgumentException $e) {
                $this->logger?->error('cron dispatch: job {job} has an invalid schedule: {message}', ['job' => $name, 'message' => $e->getMessage()]);
                continue;
            }
            $ran = $last[$name] ?? null;
            if ($dueAt !== null && ($ran === null || $ran < $dueAt->format('Y-m-d H:i:s'))) {
                $out[] = ['job' => (string) $name, 'due_at' => $dueAt->format('Y-m-d H:i:s'), 'last_run' => $ran];
            }
        }

        return $out;
    }

    /**
     * Run what is due, stopping to start new jobs once `$budgetSeconds` have been used.
     *
     * @return array<string,string> job => ran | locked | missing | failed | deferred
     */
    public function run(\DateTimeImmutable $now, int $budgetSeconds = 240): array
    {
        $started = microtime(true);
        $result = [];

        foreach ($this->due($now) as $item) {
            $job = $item['job'];
            if (microtime(true) - $started >= $budgetSeconds) {
                $result[$job] = 'deferred';
                continue;
            }
            try {
                $code = ($this->runner)($job, $this->jobs[$job]);
                $result[$job] = match ($code) {
                    0            => 'ran',
                    2            => 'locked',
                    self::MISSING => 'missing',
                    default      => 'failed',
                };
            } catch (Throwable $e) {
                $this->logger?->critical('cron dispatch: job {job} crashed: {message}', ['job' => $job, 'message' => $e->getMessage(), 'exception' => $e]);
                $result[$job] = 'failed';
            }
        }

        return $result;
    }

    /** @return array<string,string> job => last started_at (UTC) */
    private function lastRuns(): array
    {
        if ($this->jobs === []) {
            return [];
        }
        $names = array_keys($this->jobs);
        $bind = [];
        $ph = [];
        foreach ($names as $i => $n) {
            $ph[] = ":j{$i}";
            $bind["j{$i}"] = $n;
        }
        $rows = $this->db->select(
            'SELECT job_name, MAX(started_at) AS last_started FROM cron_runs WHERE job_name IN (' . implode(', ', $ph) . ') GROUP BY job_name',
            $bind,
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['job_name']] = (string) $r['last_started'];
        }

        return $out;
    }
}
