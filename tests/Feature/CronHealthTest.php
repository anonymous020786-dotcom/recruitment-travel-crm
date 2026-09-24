<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\NotificationService;
use App\Repositories\UserRepository;
use App\Services\CronHealthService;
use App\Support\CronSchedule;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class CronHealthTest extends DbTestCase
{
    private const PREFIX = 'zz-health-';
    private const NOW = '2026-09-24 10:00:00';

    /** @var list<int> */
    private array $userIds = [];
    private ?int $branchId = null;

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM cron_runs WHERE job_name LIKE '" . self::PREFIX . "%'");
        // alert() also reaches any real super admin in the dev database, not just our fixture users
        $this->db->affectingStatement("DELETE FROM notifications WHERE dedupe_key LIKE 'cron:" . self::PREFIX . "%'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'CHX-%'");
    }

    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function ran(string $job, string $startedAt, string $status = 'success', ?string $message = null): void
    {
        $this->db->insertRow('cron_runs', [
            'job_name' => self::PREFIX . $job, 'started_at' => $startedAt, 'status' => $status,
            'finished_at' => $status === 'running' ? null : $startedAt, 'message' => $message, 'items_processed' => 3,
        ]);
    }

    private function user(string $role): int
    {
        $this->branchId ??= (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'Health branch', 'code' => 'CHX-' . bin2hex(random_bytes(2))]);
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Health {$role}", 'email' => 'ch_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => (int) $this->db->selectValue('SELECT id FROM roles WHERE name = ?', [$role]),
            'primary_branch_id' => $this->branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branchId]);
        $this->userIds[] = $id;

        return $id;
    }

    /** @param array<string,array<string,mixed>> $jobs */
    private function service(array $jobs): CronHealthService
    {
        $prefixed = [];
        foreach ($jobs as $name => $def) {
            $prefixed[self::PREFIX . $name] = $def + ['script' => "cron/{$name}.php", 'schedule' => '0 9 * * *', 'ttl' => 600];
        }

        return new CronHealthService($this->db, $prefixed, $this->app->get(UserRepository::class), $this->app->get(NotificationService::class));
    }

    /** @return array<string,array<string,mixed>> overview keyed by (unprefixed) job name */
    private function states(CronHealthService $svc, string $now = self::NOW): array
    {
        $out = [];
        foreach ($svc->overview(self::at($now)) as $row) {
            $out[substr($row['job'], strlen(self::PREFIX))] = $row;
        }

        return $out;
    }

    public function test_each_state_is_derived_from_the_latest_run_and_the_schedule(): void
    {
        $this->ran('ok', '2026-09-24 09:01:00');
        $this->ran('failing', '2026-09-24 09:01:00', 'failed', 'SQLSTATE boom');
        $this->ran('stuck', '2026-09-24 09:01:00', 'running');           // ttl 600s, started an hour ago
        $this->ran('running', '2026-09-24 09:55:00', 'running');
        $this->ran('late', '2026-09-23 09:01:00');                        // today's 09:00 slot never started
        $this->ran('recovered', '2026-09-24 08:00:00', 'failed', 'old');
        $this->ran('recovered', '2026-09-24 09:02:00');                   // a later success clears the failure
        $this->ran('off', '2026-09-20 09:00:00', 'failed');

        $s = $this->states($this->service([
            'ok' => [], 'failing' => [], 'stuck' => [], 'running' => [], 'late' => [], 'recovered' => [],
            'never-ran' => [], 'never-yet' => ['schedule' => '50 9 * * *'],
            'off' => ['enabled' => false],
        ]));

        self::assertSame('ok', $s['ok']['state']);
        self::assertSame('failing', $s['failing']['state']);
        self::assertSame('SQLSTATE boom', $s['failing']['last_message']);
        self::assertSame('stuck', $s['stuck']['state']);
        self::assertSame('running', $s['running']['state']);
        self::assertSame('late', $s['late']['state']);
        self::assertSame('ok', $s['recovered']['state']);
        self::assertSame('late', $s['never-ran']['state'], 'a slot came and went with no run at all');
        self::assertSame('never', $s['never-yet']['state'], 'the first slot is still inside the grace period');
        self::assertSame('disabled', $s['off']['state'], 'disabled wins over a stale failure');
        self::assertNull($s['off']['next_due']);
    }

    public function test_a_slot_inside_the_grace_period_is_not_late(): void
    {
        $this->ran('graceful', '2026-09-23 09:00:30');
        $svc = $this->service(['graceful' => []]);

        self::assertSame('ok', $this->states($svc, '2026-09-24 09:14:00')['graceful']['state'], '14 minutes after the slot');
        self::assertSame('late', $this->states($svc, '2026-09-24 09:15:00')['graceful']['state'], 'grace used up');
    }

    public function test_dates_counts_and_next_due(): void
    {
        $this->ran('j', '2026-09-24 08:00:00', 'failed', 'x');
        $this->ran('j', '2026-09-23 08:00:00', 'failed', 'y');
        $this->ran('j', '2026-09-20 08:00:00', 'failed', 'too old');
        $this->ran('j', '2026-09-24 09:01:00');

        $j = $this->states($this->service(['j' => []]))['j'];

        self::assertSame('2026-09-24 09:01:00', $j['last_started']);
        self::assertSame('success', $j['last_status']);
        self::assertSame(3, $j['last_items']);
        self::assertSame(1, $j['failures_24h'], 'only the failure inside the last 24 hours');
        self::assertNotNull($j['last_success']);
        self::assertSame('2026-09-25 09:00:00', $j['next_due']);
    }

    public function test_history_is_newest_first_and_limited(): void
    {
        foreach (['08', '09', '10', '11'] as $h) {
            $this->ran('h', "2026-09-24 {$h}:00:00");
        }
        $svc = $this->service(['h' => []]);

        $rows = $svc->history(self::PREFIX . 'h', 3);
        self::assertSame(['2026-09-24 11:00:00', '2026-09-24 10:00:00', '2026-09-24 09:00:00'], array_column($rows, 'started_at'));
        self::assertSame(0, $rows[0]['seconds']);
    }

    public function test_administrators_are_told_once_per_job_per_state_per_day(): void
    {
        $admin = $this->user('super_admin');
        $other = $this->user('manager');
        $this->ran('bad', '2026-09-24 09:01:00', 'failed', 'disk full');
        $this->ran('good', '2026-09-24 09:01:00');
        $svc = $this->service(['bad' => [], 'good' => []]);

        $mine = fn (int $uid): int => (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'cron_alert'", [$uid]);

        self::assertGreaterThanOrEqual(1, $svc->alert(self::at(self::NOW)));
        self::assertSame(1, $mine($admin), 'one alert: the bad job; the good one is silent');
        self::assertSame(0, $mine($other), 'not for non-admins');
        $n = $this->db->selectOne("SELECT title, body FROM notifications WHERE user_id = ? AND type = 'cron_alert'", [$admin]);
        self::assertSame('Scheduled job failed: ' . self::PREFIX . 'bad', $n['title']);
        self::assertStringContainsString('disk full', $n['body']);

        $svc->alert(self::at('2026-09-24 10:15:00'));
        $svc->alert(self::at('2026-09-24 23:00:00'));
        self::assertSame(1, $mine($admin), 'the same failure the same day is not repeated');

        $svc->alert(self::at('2026-09-25 01:00:00'));
        self::assertSame(2, $mine($admin), 'still failing next day → reminded once more');
    }

    public function test_nothing_is_sent_when_everything_is_healthy(): void
    {
        $admin = $this->user('super_admin');
        $this->ran('fine', '2026-09-24 09:01:00');

        self::assertSame(0, $this->service(['fine' => []])->alert(self::at(self::NOW)));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'cron_alert'", [$admin]));
    }

    public function test_every_registered_job_has_a_script_and_a_valid_schedule(): void
    {
        $jobs = (array) $this->app->config()->get('cron.jobs', []);
        self::assertArrayHasKey('cron-health', $jobs);

        foreach ($jobs as $name => $def) {
            self::assertFileExists($this->app->basePath($def['script']), "{$name} script");
            self::assertNotNull(CronSchedule::parse($def['schedule'])->lastDueAt(self::at(self::NOW), 40 * 1440), "{$name} schedule fires");
            self::assertGreaterThan(0, $def['ttl'], "{$name} ttl");
        }
    }
}
