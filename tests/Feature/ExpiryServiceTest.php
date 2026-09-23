<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\VisaHistoryRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\ExpiryService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\JobValidator;
use Tests\Support\DbTestCase;

final class ExpiryServiceTest extends DbTestCase
{
    private ExpiryService $service;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(ExpiryService::class);
        $this->today = gmdate('Y-m-d');
        $this->branchA = $this->branch('EX-A');
        $this->branchB = $this->branch('EX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'EX-%')";
        $mine = "candidate_id IN (SELECT id FROM candidates WHERE {$like})";
        $this->db->affectingStatement("DELETE FROM visa_applications WHERE {$mine}");
        $this->db->affectingStatement("DELETE FROM medical_records WHERE {$mine}");
        $this->db->affectingStatement("DELETE FROM passports WHERE {$mine}");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('applications', 'visa', 'jobs', 'employers', 'leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        if ($this->personIds !== []) {
            $ph = implode(',', array_fill(0, count($this->personIds), '?'));
            $this->db->affectingStatement("DELETE FROM persons WHERE id IN ({$ph})", $this->personIds);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'EX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(job|employer|lead|candidate|application):'");
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function user(string $role, ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "User {$role}", 'email' => 'ex_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function candidate(User $actor): Candidate
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'Cand', 'phone' => '94' . random_int(10000000, 99999999), 'priority' => 'medium'], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    /** A live (just applied) application owned by $owner. */
    private function liveApplication(User $actor, Candidate $c, ?User $owner = null): Application
    {
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);
        $app = $this->app->get(ApplicationService::class)->create($c, $job, $actor);
        $this->db->affectingStatement('UPDATE applications SET assigned_to = ? WHERE id = ?', [$owner?->id, $app->id]);

        return $app;
    }

    private function days(int $n): string
    {
        return (new \DateTimeImmutable($this->today, new \DateTimeZone('UTC')))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    private function approvedVisa(Candidate $c, ?Application $app, string $expiry): int
    {
        return (int) $this->db->insertRow('visa_applications', [
            'public_id' => Ulid::generate(), 'candidate_id' => $c->id, 'application_id' => $app?->id, 'country' => 'AE',
            'status' => 'approved', 'approval_date' => $this->days(-30), 'expiry_date' => $expiry, 'created_by' => $this->userIds[0],
        ]);
    }

    private function notified(string $type, int $userId): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$userId, $type]);
    }

    public function test_visa_reminder_reaches_the_owner_and_the_branch_visa_team_once_per_window(): void
    {
        $manager = $this->user('manager');
        $owner = $this->user('recruitment');
        $team = $this->user('visa');
        $otherBranchTeam = $this->user('visa', $this->branchB);
        $c = $this->candidate($manager);
        $app = $this->liveApplication($manager, $c, $owner);
        $this->approvedVisa($c, $app, $this->days(25));

        self::assertSame(2, $this->service->remindVisas($this->today));
        self::assertSame(1, $this->notified('visa_expiring', $owner->id));
        self::assertSame(1, $this->notified('visa_expiring', $team->id));
        self::assertSame(0, $this->notified('visa_expiring', $otherBranchTeam->id), 'other branches are not told');

        $this->service->remindVisas($this->today);
        $this->service->remindVisas($this->days(10)); // still the 30-day window
        self::assertSame(1, $this->notified('visa_expiring', $owner->id), 'same window, no duplicate');
        self::assertSame(1, $this->notified('visa_expiring', $team->id));
    }

    public function test_a_new_window_and_the_expired_bucket_each_notify_again(): void
    {
        $manager = $this->user('manager');
        $owner = $this->user('recruitment');
        $c = $this->candidate($manager);
        $this->approvedVisa($c, $this->liveApplication($manager, $c, $owner), $this->days(60));

        $this->service->remindVisas($this->today);                 // inside 90
        self::assertSame(1, $this->notified('visa_expiring', $owner->id));
        $this->service->remindVisas($this->days(40));              // now inside 30
        self::assertSame(2, $this->notified('visa_expiring', $owner->id));
        $this->service->remindVisas($this->days(70));              // lapsed
        self::assertSame(3, $this->notified('visa_expiring', $owner->id));
        $this->service->remindVisas($this->days(80));
        self::assertSame(3, $this->notified('visa_expiring', $owner->id), 'the expired bucket fires once');
    }

    public function test_visas_outside_every_window_or_not_approved_are_left_alone(): void
    {
        $manager = $this->user('manager');
        $owner = $this->user('recruitment');
        $c = $this->candidate($manager);
        $app = $this->liveApplication($manager, $c, $owner);
        $this->approvedVisa($c, $app, $this->days(400));
        $id = $this->approvedVisa($c, $app, $this->days(10));
        $this->db->affectingStatement("UPDATE visa_applications SET status = 'cancelled' WHERE id = ?", [$id]);

        self::assertSame(0, $this->service->remindVisas($this->today));
    }

    public function test_owner_falls_back_to_the_candidates_counselor(): void
    {
        $manager = $this->user('manager');
        $counselor = $this->user('counselor');
        $c = $this->candidate($manager);
        $this->db->affectingStatement('UPDATE candidates SET assigned_counselor = ? WHERE id = ?', [$counselor->id, $c->id]);
        $this->approvedVisa($c, null, $this->days(20));

        $this->service->remindVisas($this->today);

        self::assertSame(1, $this->notified('visa_expiring', $counselor->id));
    }

    public function test_expiring_lapsed_visas_are_flipped_by_the_system_once(): void
    {
        $manager = $this->user('manager');
        $c = $this->candidate($manager);
        $lapsed = $this->approvedVisa($c, null, $this->days(-1));
        $valid = $this->approvedVisa($c, null, $this->days(200));

        self::assertSame(1, $this->service->expireVisas($this->today));
        self::assertSame(0, $this->service->expireVisas($this->today), 'idempotent');

        self::assertSame('expired', $this->db->selectValue('SELECT status FROM visa_applications WHERE id = ?', [$lapsed]));
        self::assertSame(2, (int) $this->db->selectValue('SELECT record_version FROM visa_applications WHERE id = ?', [$lapsed]));
        self::assertSame('approved', $this->db->selectValue('SELECT status FROM visa_applications WHERE id = ?', [$valid]));

        $row = $this->db->selectOne('SELECT from_status, to_status, changed_by FROM visa_status_history WHERE visa_application_id = ?', [$lapsed]);
        self::assertSame(['approved', 'expired', null], [$row['from_status'], $row['to_status'], $row['changed_by']]);
        $history = $this->app->get(VisaHistoryRepository::class)->forVisa($lapsed);
        self::assertNull($history[0]['by'], 'system-written rows read back with no user');
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='visa' AND action='status_changed' AND record_id = ?", [$lapsed]));
    }

    public function test_medical_reminders_only_for_candidates_with_a_live_application(): void
    {
        $manager = $this->user('manager');
        $owner = $this->user('recruitment');
        $team = $this->user('visa');
        $live = $this->candidate($manager);
        $app = $this->liveApplication($manager, $live, $owner);
        $idle = $this->candidate($manager);

        foreach ([[$live, $app], [$idle, null]] as [$c, $a]) {
            $this->db->insertRow('medical_records', [
                'public_id' => Ulid::generate(), 'candidate_id' => $c->id, 'application_id' => $a?->id, 'status' => 'fit', 'result' => 'fit',
                'report_date' => $this->days(-80), 'expires_at' => $this->days(10), 'created_by' => $manager->id,
            ]);
        }

        self::assertSame(2, $this->service->remindMedical($this->today));
        self::assertSame(1, $this->notified('medical_expiring', $owner->id));
        self::assertSame(1, $this->notified('medical_expiring', $team->id));

        $this->service->remindMedical($this->today);
        self::assertSame(1, $this->notified('medical_expiring', $owner->id));
    }

    public function test_passport_reminders_go_to_the_counselor_only_while_the_candidate_is_active(): void
    {
        $manager = $this->user('manager');
        $counselor = $this->user('counselor');
        $live = $this->candidate($manager);
        $this->liveApplication($manager, $live);
        $idle = $this->candidate($manager);
        foreach ([$live, $idle] as $i => $c) {
            $this->db->affectingStatement('UPDATE candidates SET assigned_counselor = ? WHERE id = ?', [$counselor->id, $c->id]);
            $this->db->insertRow('passports', ['candidate_id' => $c->id, 'passport_number' => 'EXP' . random_int(100000, 999999) . $i, 'expiry_date' => $this->days(60)]);
        }

        self::assertSame(1, $this->service->remindPassports($this->today));
        self::assertSame(1, $this->notified('passport_expiring', $counselor->id));

        $this->service->remindPassports($this->today);
        self::assertSame(1, $this->notified('passport_expiring', $counselor->id), 'deduped');
    }
}
