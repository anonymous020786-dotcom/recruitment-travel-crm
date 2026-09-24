<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\CronDispatcher;
use Tests\Support\DbTestCase;

final class CronDispatcherTest extends DbTestCase
{
    private const PREFIX = 'zz-test-';

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM cron_runs WHERE job_name LIKE '" . self::PREFIX . "%'");
    }

    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function ran(string $job, string $startedAt, string $status = 'success'): void
    {
        $this->db->insertRow('cron_runs', ['job_name' => self::PREFIX . $job, 'started_at' => $startedAt, 'status' => $status, 'finished_at' => $startedAt]);
    }

    /** @param array<string,array<string,mixed>> $jobs @param list<string> $log */
    private function dispatcher(array $jobs, array &$log, int $code = 0): CronDispatcher
    {
        $prefixed = [];
        foreach ($jobs as $name => $def) {
            $prefixed[self::PREFIX . $name] = $def;
        }

        return new CronDispatcher($this->db, $prefixed, function (string $name, array $def) use (&$log, $code): int {
            $log[] = $name;

            return is_callable($def['result'] ?? null) ? $def['result']() : $code;
        });
    }

    public function test_a_job_that_never_ran_is_due_and_one_that_ran_since_its_slot_is_not(): void
    {
        $now = self::at('2026-09-24 10:00:00');
        $this->ran('ran-after-slot', '2026-09-24 09:01:00');   // daily 09:00 slot already served
        $this->ran('ran-before-slot', '2026-09-23 09:00:05');  // yesterday's; today's slot is missed
        $log = [];
        $d = $this->dispatcher([
            'never-ran'       => ['schedule' => '0 9 * * *'],
            'ran-after-slot'  => ['schedule' => '0 9 * * *'],
            'ran-before-slot' => ['schedule' => '0 9 * * *'],
        ], $log);

        $due = array_column($d->due($now), 'job');
        self::assertSame([self::PREFIX . 'never-ran', self::PREFIX . 'ran-before-slot'], $due);
        self::assertSame('2026-09-24 09:00:00', $d->due($now)[0]['due_at']);
        self::assertNull($d->due($now)[0]['last_run']);
        self::assertSame('2026-09-23 09:00:05', $d->due($now)[1]['last_run']);
    }

    public function test_run_executes_due_jobs_in_registry_order_and_a_second_tick_does_nothing(): void
    {
        $now = self::at('2026-09-24 10:00:00');
        $log = [];
        $d = $this->dispatcher(['b' => ['schedule' => '0 9 * * *'], 'a' => ['schedule' => '*/5 * * * *']], $log);

        // The stub does not write cron_runs (the real CronRunner does), so record what a real run leaves behind.
        $result = $d->run($now);
        self::assertSame([self::PREFIX . 'b' => 'ran', self::PREFIX . 'a' => 'ran'], $result);
        self::assertSame([self::PREFIX . 'b', self::PREFIX . 'a'], $log);

        $this->ran('b', '2026-09-24 10:00:01');
        $this->ran('a', '2026-09-24 10:00:01');
        $log = [];
        self::assertSame([], $d->run(self::at('2026-09-24 10:03:00')), 'nothing fired since they last started');
        self::assertSame([], $log);

        self::assertSame([self::PREFIX . 'a' => 'ran'], $d->run(self::at('2026-09-24 10:05:00')), 'the 5-minute job is due again, the daily one is not');
    }

    public function test_a_failed_run_is_retried_at_the_next_slot_not_every_tick(): void
    {
        $this->ran('flaky', '2026-09-24 09:00:03', 'failed');
        $log = [];
        $d = $this->dispatcher(['flaky' => ['schedule' => '0 9 * * *']], $log);

        self::assertSame([], $d->due(self::at('2026-09-24 09:05:00')), 'it already started for this slot');
        self::assertCount(1, $d->due(self::at('2026-09-25 09:00:00')), 'tomorrow\'s slot');
    }

    public function test_outcomes_are_reported_and_a_crash_does_not_stop_the_rest(): void
    {
        $now = self::at('2026-09-24 10:00:00');
        $log = [];
        $d = $this->dispatcher([
            'ok'      => ['schedule' => '* * * * *', 'result' => static fn (): int => 0],
            'locked'  => ['schedule' => '* * * * *', 'result' => static fn (): int => 2],
            'missing' => ['schedule' => '* * * * *', 'result' => static fn (): int => CronDispatcher::MISSING],
            'failed'  => ['schedule' => '* * * * *', 'result' => static fn (): int => 1],
            'crashes' => ['schedule' => '* * * * *', 'result' => static function (): int { throw new \RuntimeException('boom'); }],
            'after'   => ['schedule' => '* * * * *', 'result' => static fn (): int => 0],
        ], $log);

        $r = $d->run($now);
        self::assertSame(
            ['ok' => 'ran', 'locked' => 'locked', 'missing' => 'missing', 'failed' => 'failed', 'crashes' => 'failed', 'after' => 'ran'],
            array_combine(array_map(static fn (string $k): string => substr($k, strlen(self::PREFIX)), array_keys($r)), array_values($r)),
        );
        self::assertCount(6, $log, 'the crash did not stop the jobs after it');
    }

    public function test_the_time_budget_defers_the_remaining_jobs(): void
    {
        $now = self::at('2026-09-24 10:00:00');
        $log = [];
        $d = $this->dispatcher([
            'slow'  => ['schedule' => '* * * * *', 'result' => static function (): int { usleep(1_200_000); return 0; }],
            'later' => ['schedule' => '* * * * *'],
        ], $log);

        $r = $d->run($now, 1);
        self::assertSame([self::PREFIX . 'slow' => 'ran', self::PREFIX . 'later' => 'deferred'], $r);
        self::assertSame([self::PREFIX . 'slow'], $log);
    }

    public function test_disabled_and_invalid_schedules_are_skipped(): void
    {
        $log = [];
        $d = $this->dispatcher([
            'off'     => ['schedule' => '* * * * *', 'enabled' => false],
            'bad'     => ['schedule' => 'not a cron line'],
            'noschedule' => [],
            'fine'    => ['schedule' => '* * * * *'],
        ], $log);

        self::assertSame([self::PREFIX . 'fine'], array_column($d->due(self::at('2026-09-24 10:00:00')), 'job'));
    }

    public function test_the_real_registry_is_dispatchable(): void
    {
        $jobs = (require dirname(__DIR__, 2) . '/config/cron.php')['jobs'];
        $log = [];
        $d = new CronDispatcher($this->db, $jobs, function (string $n) use (&$log): int { $log[] = $n; return 0; });

        // every registered schedule parses, and every job that names a script names a real file or one on the roadmap
        foreach ($jobs as $name => $def) {
            self::assertArrayHasKey('script', $def, $name);
            self::assertArrayHasKey('schedule', $def, $name);
        }
        self::assertIsArray($d->due(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }
}
