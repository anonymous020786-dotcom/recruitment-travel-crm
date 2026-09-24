<?php

declare(strict_types=1);

namespace App\Services;

use App\Notifications\NotificationService;
use App\Repositories\UserRepository;
use App\Support\CronSchedule;
use App\Support\Db;

/**
 * Health of the scheduled jobs, read from the `cron_runs` ledger against `config/cron.php`.
 *
 * A job is, in priority order: `disabled`; `stuck` (a run still marked running after its lock TTL — the
 * process died); `failing` (its latest run failed); `late` (its schedule fired more than GRACE_MINUTES ago
 * and it has not started since); `running`; `never` (no run on record yet); `ok`. The last three are healthy.
 * `alert()` tells the administrators about the unhealthy ones, once per job per state per day.
 */
final class CronHealthService
{
    public const GRACE_MINUTES = 15;
    public const UNHEALTHY = ['stuck', 'failing', 'late'];
    private const ADMIN_ROLE = 'super_admin';

    /** @param array<string,array<string,mixed>> $jobs job name => definition (`script`, `schedule`, `ttl`, optional `enabled`) */
    public function __construct(
        private readonly Db $db,
        private readonly array $jobs,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return array<string,array<string,mixed>> the configured registry */
    public function jobs(): array
    {
        return $this->jobs;
    }

    public function has(string $job): bool
    {
        return isset($this->jobs[$job]);
    }

    /**
     * One row per configured job, in registry order.
     *
     * @return list<array{job:string,script:string,schedule:string,enabled:bool,state:string,last_started:?string,last_finished:?string,last_status:?string,last_items:?int,last_message:?string,last_success:?string,next_due:?string,failures_24h:int}>
     */
    public function overview(\DateTimeImmutable $now): array
    {
        $latest = $this->latestRuns();
        $success = $this->lastSuccesses();
        $failures = $this->recentFailures($now);
        $out = [];

        foreach ($this->jobs as $name => $def) {
            $name = (string) $name;
            $run = $latest[$name] ?? null;
            $enabled = ($def['enabled'] ?? true) !== false;

            $schedule = null;
            try {
                $schedule = CronSchedule::parse((string) ($def['schedule'] ?? ''));
            } catch (\InvalidArgumentException) {
            }

            $out[] = [
                'job' => $name,
                'script' => (string) ($def['script'] ?? ''),
                'schedule' => (string) ($def['schedule'] ?? ''),
                'enabled' => $enabled,
                'state' => $this->stateOf($enabled, $schedule, (int) ($def['ttl'] ?? 900), $run, $now),
                'last_started' => $run['started_at'] ?? null,
                'last_finished' => $run['finished_at'] ?? null,
                'last_status' => $run['status'] ?? null,
                'last_items' => isset($run['items_processed']) ? (int) $run['items_processed'] : null,
                'last_message' => $run['message'] ?? null,
                'last_success' => $success[$name] ?? null,
                'next_due' => $enabled ? $schedule?->nextDueAt($now)?->format('Y-m-d H:i:s') : null,
                'failures_24h' => $failures[$name] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * The most recent runs of one job, newest first.
     *
     * @return list<array{id:int,started_at:string,finished_at:?string,status:string,items_processed:int,message:?string,seconds:?int}>
     */
    public function history(string $job, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $rows = $this->db->select(
            "SELECT id, started_at, finished_at, status, items_processed, message,
                    TIMESTAMPDIFF(SECOND, started_at, finished_at) AS seconds
             FROM cron_runs WHERE job_name = :j ORDER BY id DESC LIMIT {$limit}",
            ['j' => $job],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'started_at' => (string) $r['started_at'],
            'finished_at' => $r['finished_at'] !== null ? (string) $r['finished_at'] : null,
            'status' => (string) $r['status'], 'items_processed' => (int) $r['items_processed'],
            'message' => $r['message'] !== null ? (string) $r['message'] : null,
            'seconds' => $r['seconds'] !== null ? (int) $r['seconds'] : null,
        ], $rows);
    }

    /** Notify every active super admin of each unhealthy job. @return int notifications attempted */
    public function alert(\DateTimeImmutable $now): int
    {
        $unhealthy = array_values(array_filter($this->overview($now), static fn (array $j): bool => in_array($j['state'], self::UNHEALTHY, true)));
        if ($unhealthy === []) {
            return 0;
        }
        $admins = $this->users->activeIdsByRole(self::ADMIN_ROLE);
        $date = $now->format('Y-m-d');
        $sent = 0;

        foreach ($unhealthy as $j) {
            [$title, $body] = $this->describe($j);
            foreach ($admins as $adminId) {
                $this->notifications->notify(
                    userId: $adminId,
                    type: 'cron_alert',
                    title: $title,
                    body: $body,
                    dedupeKey: "cron:{$j['job']}:{$j['state']}:{$date}:u{$adminId}",
                );
                $sent++;
            }
        }

        return $sent;
    }

    // ---- internals -------------------------------------------------

    /** @param array<string,mixed> $j */
    private function describe(array $j): array
    {
        return match ($j['state']) {
            'failing' => ["Scheduled job failed: {$j['job']}", 'Last run ' . $j['last_started'] . ' UTC: ' . ($j['last_message'] ?? 'no message recorded')],
            'stuck'   => ["Scheduled job stuck: {$j['job']}", 'Started ' . $j['last_started'] . ' UTC and never finished — the process probably died. It will start again at its next slot.'],
            default   => ["Scheduled job is late: {$j['job']}", 'Schedule ' . $j['schedule'] . ' UTC, last started ' . ($j['last_started'] ?? 'never') . '. Check that the cron line or dispatcher is running.'],
        };
    }

    /** @param array<string,mixed>|null $run */
    private function stateOf(bool $enabled, ?CronSchedule $schedule, int $ttl, ?array $run, \DateTimeImmutable $now): string
    {
        if (!$enabled) {
            return 'disabled';
        }
        if ($run !== null && $run['status'] === 'running' && strtotime($run['started_at'] . ' UTC') + $ttl < $now->getTimestamp()) {
            return 'stuck';
        }
        if ($run !== null && $run['status'] === 'failed') {
            return 'failing';
        }

        $dueAt = $schedule?->lastDueAt($now);
        if ($dueAt !== null
            && $dueAt->getTimestamp() + self::GRACE_MINUTES * 60 <= $now->getTimestamp()
            && ($run === null || strtotime($run['started_at'] . ' UTC') < $dueAt->getTimestamp())) {
            return 'late';
        }

        return match (true) {
            $run === null              => 'never',
            $run['status'] === 'running' => 'running',
            default                    => 'ok',
        };
    }

    /** @return array<string,array<string,mixed>> the latest run of each job */
    private function latestRuns(): array
    {
        $rows = $this->db->select(
            'SELECT r.job_name, r.started_at, r.finished_at, r.status, r.items_processed, r.message
             FROM cron_runs r JOIN (SELECT job_name, MAX(id) AS id FROM cron_runs GROUP BY job_name) m ON m.id = r.id',
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['job_name']] = $r;
        }

        return $out;
    }

    /** @return array<string,string> */
    private function lastSuccesses(): array
    {
        $rows = $this->db->select("SELECT job_name, MAX(finished_at) AS at FROM cron_runs WHERE status = 'success' GROUP BY job_name");
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['job_name']] = (string) $r['at'];
        }

        return $out;
    }

    /** @return array<string,int> */
    private function recentFailures(\DateTimeImmutable $now): array
    {
        $rows = $this->db->select(
            "SELECT job_name, COUNT(*) AS n FROM cron_runs WHERE status = 'failed' AND started_at >= :since GROUP BY job_name",
            ['since' => $now->setTimezone(new \DateTimeZone('UTC'))->modify('-24 hours')->format('Y-m-d H:i:s')],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['job_name']] = (int) $r['n'];
        }

        return $out;
    }
}
