<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\ApplicationRepository;
use App\Repositories\MedicalRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\MedicalService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\JobValidator;
use App\Validators\MedicalValidator;
use Tests\Support\DbTestCase;

final class MedicalServiceTest extends DbTestCase
{
    private MedicalService $service;
    private ApplicationService $applications;
    private MedicalRepository $repo;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(MedicalService::class);
        $this->applications = $this->app->get(ApplicationService::class);
        $this->repo = $this->app->get(MedicalRepository::class);
        $this->branchA = $this->branch('MX-A');
        $this->branchB = $this->branch('MX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'MX-%')";
        $this->db->affectingStatement("DELETE FROM medical_records WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('applications', 'medical', 'jobs', 'employers', 'leads', 'candidates')");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'MX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(job|employer|lead|candidate|application):'");
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'mx_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function scope(): \App\Auth\BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($this->app->get(\App\Repositories\UserRepository::class)->findById($this->userIds[0]));
    }

    private function candidate(User $actor): Candidate
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'Cand', 'phone' => '94' . random_int(10000000, 99999999), 'priority' => 'medium'], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    /** An application walked (legally) up to $target. */
    private function applicationAt(User $actor, Candidate $c, string $target): Application
    {
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);
        $app = $this->applications->create($c, $job, $actor);

        foreach (['shortlisted', 'interview_scheduled', 'interview_completed', 'selected', 'offer_received', 'offer_accepted', 'medical_pending', 'medical_completed'] as $step) {
            $app = $this->applications->changeStatus($app, $step, $actor, $app->recordVersion);
            if ($step === $target) {
                break;
            }
        }

        return $app;
    }

    private function fresh(Application $a): Application
    {
        return $this->app->get(ApplicationRepository::class)->findById($a->id, $this->scope());
    }

    /** @return array{medical_center:?string,appointment_date:?string,notes:?string} */
    private function book(?string $date = null, ?string $centre = 'Gulf Clinic'): array
    {
        return (new MedicalValidator())->book(['medical_center' => $centre, 'appointment_date' => $date ?? '']);
    }

    /** @return array{result:string,report_date:string,expires_at:?string,notes:?string} */
    private function verdict(string $result, array $extra = []): array
    {
        return (new MedicalValidator())->result(['result' => $result] + $extra);
    }

    public function test_booking_without_a_date_is_pending_and_with_a_date_is_scheduled(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);

        $pending = $this->service->book($c, null, $this->book(), $actor);
        self::assertSame('pending', $pending->status);
        self::assertSame('pending', $pending->result);

        $c2 = $this->candidate($actor);
        $scheduled = $this->service->book($c2, null, $this->book(gmdate('Y-m-d', strtotime('+3 days'))), $actor);
        self::assertSame('scheduled', $scheduled->status);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='medical' AND action='created' AND record_id = ?", [$scheduled->id]));
    }

    public function test_one_open_medical_per_candidate_and_application(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $this->service->book($c, null, $this->book(), $actor);

        try {
            $this->service->book($c, null, $this->book(), $actor);
            self::fail('a second general medical must be refused');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $app = $this->applicationAt($actor, $c, 'medical_pending');
        self::assertSame('pending', $this->service->book($c, $app, $this->book(), $actor)->status, 'a medical tied to an application is separate');
    }

    public function test_application_must_belong_to_the_candidate_and_be_open(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $other = $this->candidate($actor);
        $otherApp = $this->applicationAt($actor, $other, 'shortlisted');

        try {
            $this->service->book($c, $otherApp, $this->book(), $actor);
            self::fail('foreign application');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $cancelled = $this->applications->changeStatus($otherApp, 'cancelled', $actor, $otherApp->recordVersion, 'Withdrew');
        $this->expectException(DomainRuleException::class);
        $this->service->book($other, $cancelled, $this->book(), $actor);
    }

    public function test_full_happy_path_fit_defaults_expiry_and_completes_the_application_medical(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c, 'medical_pending');
        $m = $this->service->book($c, $app, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $actor);

        $m = $this->service->markAttended($m, gmdate('Y-m-d'), $actor);
        self::assertSame('completed', $m->status);
        self::assertSame(gmdate('Y-m-d'), $m->medicalDate);

        $m = $this->service->recordResult($m, $this->verdict('fit'), $actor);
        self::assertSame('fit', $m->status);
        self::assertSame('fit', $m->result);
        self::assertSame((new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('+' . MedicalService::DEFAULT_VALIDITY_DAYS . ' days')->format('Y-m-d'), $m->expiresAt);
        self::assertSame('valid', $m->expiryState());
        self::assertSame('medical_completed', $this->fresh($app)->status);
    }

    public function test_fit_with_explicit_expiry_uses_it(): void
    {
        $actor = $this->actor();
        $m = $this->service->book($this->candidate($actor), null, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $actor);
        $expires = gmdate('Y-m-d', strtotime('+20 days'));

        $m = $this->service->recordResult($m, $this->verdict('fit', ['expires_at' => $expires]), $actor);

        self::assertSame($expires, $m->expiresAt);
        self::assertSame('expiring', $m->expiryState());
    }

    public function test_unfit_leaves_the_application_and_notifies_its_owner(): void
    {
        $manager = $this->actor();
        $owner = $this->actor('recruitment');
        $c = $this->candidate($manager);
        $app = $this->applicationAt($manager, $c, 'medical_pending');
        $this->db->affectingStatement('UPDATE applications SET assigned_to = ? WHERE id = ?', [$owner->id, $app->id]);
        $m = $this->service->book($c, $app, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $manager);

        $m = $this->service->recordResult($m, $this->verdict('unfit', ['expires_at' => gmdate('Y-m-d', strtotime('+30 days'))]), $manager);

        self::assertSame('unfit', $m->status);
        self::assertNull($m->expiresAt, 'only a fit certificate has an expiry');
        self::assertSame('medical_pending', $this->fresh($app)->status);
        self::assertTrue($this->db->exists("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'medical_unfit'", [$owner->id]));
    }

    public function test_retest_is_final_for_the_record_and_a_new_one_can_be_booked(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $m = $this->service->book($c, null, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $actor);
        $m = $this->service->recordResult($m, $this->verdict('retest'), $actor);
        self::assertSame('retest', $m->status);

        try {
            $this->service->recordResult($m, $this->verdict('fit'), $actor);
            self::fail('a finished record cannot be re-judged');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        self::assertSame('pending', $this->service->book($c, null, $this->book(), $actor)->status);
    }

    public function test_result_needs_the_exam_to_have_been_booked_first(): void
    {
        $actor = $this->actor();
        $m = $this->service->book($this->candidate($actor), null, $this->book(), $actor);

        $this->expectException(DomainRuleException::class);
        $this->service->recordResult($m, $this->verdict('fit'), $actor);
    }

    public function test_reschedule_only_before_the_exam_and_keeps_a_date_once_scheduled(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $m = $this->service->book($c, null, $this->book(), $actor);

        $m = $this->service->reschedule($m, $this->book(gmdate('Y-m-d', strtotime('+2 days')), 'City Clinic'), $actor);
        self::assertSame('scheduled', $m->status);
        self::assertSame('City Clinic', $m->medicalCenter);

        try {
            $this->service->reschedule($m, $this->book(null), $actor);
            self::fail('cannot clear the date of a scheduled medical');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $attended = $this->service->markAttended($m, gmdate('Y-m-d'), $actor);
        $this->expectException(DomainRuleException::class);
        $this->service->reschedule($attended, $this->book(gmdate('Y-m-d', strtotime('+2 days'))), $actor);
    }

    public function test_delete_only_unstarted_records(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $m = $this->service->book($c, null, $this->book(), $actor);
        $this->service->delete($m, $actor);
        self::assertNull($this->repo->findById($m->id, $this->scope()));

        $started = $this->service->markAttended($this->service->book($c, null, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $actor), gmdate('Y-m-d'), $actor);
        $this->expectException(DomainRuleException::class);
        $this->service->delete($started, $actor);
    }

    public function test_permissions_and_branch_scope(): void
    {
        $manager = $this->actor();
        $c = $this->candidate($manager);

        foreach (['counselor', 'read_only'] as $role) {
            try {
                $this->service->book($c, null, $this->book(), $this->actor($role));
                self::fail("{$role} must not create");
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }

        $m = $this->service->book($c, null, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $manager);
        $outsider = $this->actor('manager', $this->branchB);
        foreach ([
            fn () => $this->service->book($c, null, $this->book(), $outsider),
            fn () => $this->service->markAttended($m, gmdate('Y-m-d'), $outsider),
            fn () => $this->service->recordResult($m, $this->verdict('fit'), $this->actor('read_only')),
            fn () => $this->service->delete($m, $this->actor('read_only')),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('expected AuthorizationException');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }

        self::assertNull($this->repo->findByPublicId($m->publicId, $this->app->get(BranchScopeResolver::class)->resolve($outsider)));
    }

    public function test_listing_filters_expiring_and_expired_certificates(): void
    {
        $actor = $this->actor();
        $soon = $this->service->recordResult($this->service->book($this->candidate($actor), null, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $actor), $this->verdict('fit', ['expires_at' => gmdate('Y-m-d', strtotime('+10 days'))]), $actor);
        $far = $this->service->recordResult($this->service->book($this->candidate($actor), null, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $actor), $this->verdict('fit'), $actor);
        $lapsed = $this->service->recordResult($this->service->book($this->candidate($actor), null, $this->book(gmdate('Y-m-d', strtotime('+1 day'))), $actor), $this->verdict('fit', ['expires_at' => gmdate('Y-m-d', strtotime('+5 days'))]), $actor);
        $this->db->affectingStatement('UPDATE medical_records SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d', strtotime('-2 days')), $lapsed->id]);

        $ids = static fn (\App\Support\Page $p): array => array_map(static fn ($m) => $m->id, $p->items);
        $q = static fn (string $expiry): \App\Support\ListQuery => \App\Support\ListQuery::of(['filters' => ['expiry' => $expiry]]);

        $expiring = $ids($this->repo->paginate($q('expiring'), $this->scope()));
        self::assertContains($soon->id, $expiring);
        self::assertNotContains($far->id, $expiring);
        self::assertNotContains($lapsed->id, $expiring);
        self::assertSame([$lapsed->id], $ids($this->repo->paginate($q('expired'), $this->scope())));
    }

    public function test_validator_rules(): void
    {
        $v = new MedicalValidator();

        foreach ([
            fn () => $v->book(['appointment_date' => '2020-01-01']),
            fn () => $v->book(['appointment_date' => '2026-02-30']),
            fn () => $v->attended(['medical_date' => gmdate('Y-m-d', strtotime('+2 days'))]),
            fn () => $v->result(['result' => 'maybe']),
            fn () => $v->result(['result' => 'fit', 'report_date' => '2026-01-10', 'expires_at' => '2026-01-01']),
            fn () => $v->result(['result' => 'fit', 'report_date' => gmdate('Y-m-d', strtotime('+3 days'))]),
        ] as $case) {
            try {
                $case();
                self::fail('expected ValidationException');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(gmdate('Y-m-d'), $v->result(['result' => 'unfit'])['report_date'], 'report date defaults to today');
    }
}
