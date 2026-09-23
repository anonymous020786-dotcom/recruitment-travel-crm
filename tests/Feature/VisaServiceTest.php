<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Models\VisaApplication;
use App\Repositories\ApplicationRepository;
use App\Repositories\VisaHistoryRepository;
use App\Repositories\VisaRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\VisaService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\JobValidator;
use App\Validators\VisaValidator;
use Tests\Support\DbTestCase;

final class VisaServiceTest extends DbTestCase
{
    private VisaService $service;
    private ApplicationService $applications;
    private VisaRepository $repo;
    private VisaHistoryRepository $history;
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
        $this->service = $this->app->get(VisaService::class);
        $this->applications = $this->app->get(ApplicationService::class);
        $this->repo = $this->app->get(VisaRepository::class);
        $this->history = $this->app->get(VisaHistoryRepository::class);
        $this->branchA = $this->branch('VX-A');
        $this->branchB = $this->branch('VX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'VX-%')";
        $this->db->affectingStatement("DELETE FROM visa_applications WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'VX-%'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'vx_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function scope(?User $as = null): \App\Auth\BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($as ?? $this->app->get(\App\Repositories\UserRepository::class)->findById($this->userIds[0]));
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

        foreach (['shortlisted', 'interview_scheduled', 'interview_completed', 'selected', 'offer_received', 'offer_accepted', 'medical_pending', 'medical_completed', 'visa_processing'] as $step) {
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

    private function visaFresh(VisaApplication $v): VisaApplication
    {
        return $this->repo->findById($v->id, $this->scope());
    }

    /** @return array<string,?string> */
    private function details(array $over = []): array
    {
        return (new VisaValidator())->details($over + ['country' => 'ae', 'visa_type' => 'Employment']);
    }

    /** @return array<string,?string> */
    private function move(string $to, array $extra = []): array
    {
        return (new VisaValidator())->status(['status' => $to] + $extra);
    }

    /** @return list<string> */
    private function trail(VisaApplication $v): array
    {
        return array_reverse(array_map(static fn (array $h): string => $h['to'], $this->history->forVisa($v->id)));
    }

    /** Advance a visa by legal steps: returns the refreshed visa. */
    private function step(VisaApplication $v, string $to, User $actor, array $extra = []): VisaApplication
    {
        return $this->service->changeStatus($v, $this->move($to, $extra), $actor, $v->recordVersion);
    }

    public function test_create_normalises_country_and_defaults_sponsor_from_the_employer(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c, 'medical_completed');

        $v = $this->service->create($c, $app, $this->details(), $actor);

        self::assertSame('AE', $v->country);
        self::assertSame('not_started', $v->status);
        self::assertSame('Al Noor', $v->sponsor);
        self::assertSame(['not_started'], $this->trail($v));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='visa' AND action='created' AND record_id = ?", [$v->id]));
    }

    public function test_starting_a_visa_moves_a_medical_completed_application_to_visa_processing(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c, 'medical_completed');

        $this->service->create($c, $app, $this->details(), $actor);

        self::assertSame('visa_processing', $this->fresh($app)->status);
    }

    public function test_application_must_have_completed_its_medical(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c, 'medical_pending');

        try {
            $this->service->create($c, $app, $this->details(), $actor);
            self::fail('medical still pending');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('medical', $e->getMessage());
        }
        self::assertSame([], $this->repo->forCandidate($c->id));
    }

    public function test_foreign_application_and_unknown_country_are_refused(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $other = $this->candidate($actor);
        $otherApp = $this->applicationAt($actor, $other, 'medical_completed');

        try {
            $this->service->create($c, $otherApp, $this->details(), $actor);
            self::fail('foreign application');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        $this->service->create($c, null, $this->details(['country' => 'zz']), $actor);
    }

    public function test_one_live_visa_per_application_but_a_rejected_one_can_be_redone(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c, 'medical_completed');
        $v = $this->service->create($c, $app, $this->details(), $actor);

        try {
            $this->service->create($c, $this->fresh($app), $this->details(), $actor);
            self::fail('duplicate live visa');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $v = $this->step($v, 'submitted', $actor);
        $this->service->changeStatus($v, $this->move('rejected', ['reason' => 'Passport issue']), $actor, $v->recordVersion);

        self::assertSame('not_started', $this->service->create($c, $this->fresh($app), $this->details(), $actor)->status);
    }

    public function test_full_path_to_approval_advances_the_application_and_notifies_the_owner(): void
    {
        $manager = $this->actor();
        $owner = $this->actor('recruitment');
        $c = $this->candidate($manager);
        $app = $this->applicationAt($manager, $c, 'medical_completed');
        $this->db->affectingStatement('UPDATE applications SET assigned_to = ? WHERE id = ?', [$owner->id, $app->id]);
        $v = $this->service->create($c, $this->fresh($app), $this->details(), $manager);

        $v = $this->step($v, 'documents_pending', $manager);
        $v = $this->step($v, 'submitted', $manager);
        self::assertSame(gmdate('Y-m-d'), $v->submissionDate, 'submission date defaults to today');
        $v = $this->step($v, 'under_processing', $manager);
        $expiry = gmdate('Y-m-d', strtotime('+2 years'));
        $v = $this->step($v, 'approved', $manager, ['expiry_date' => $expiry, 'visa_number' => 'V-778']);

        self::assertSame('approved', $v->status);
        self::assertSame($expiry, $v->expiryDate);
        self::assertSame('V-778', $v->visaNumber);
        self::assertSame(gmdate('Y-m-d'), $v->approvalDate);
        self::assertSame(['not_started', 'documents_pending', 'submitted', 'under_processing', 'approved'], $this->trail($v));
        self::assertSame('visa_approved', $this->fresh($app)->status);
        self::assertTrue($this->db->exists("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'visa_approved'", [$owner->id]));
    }

    public function test_approval_needs_a_future_expiry_after_the_approval_date(): void
    {
        $actor = $this->actor();
        $v = $this->step($this->service->create($this->candidate($actor), null, $this->details(), $actor), 'submitted', $actor);

        foreach ([[], ['expiry_date' => gmdate('Y-m-d')], ['expiry_date' => '2020-01-01']] as $extra) {
            try {
                $this->step($v, 'approved', $actor, $extra);
                self::fail('approval without a valid expiry must be refused');
            } catch (ValidationException) {
                self::assertSame('submitted', $this->visaFresh($v)->status);
            }
        }
    }

    public function test_illegal_transition_writes_nothing(): void
    {
        $actor = $this->actor();
        $v = $this->service->create($this->candidate($actor), null, $this->details(), $actor);

        try {
            $this->step($v, 'approved', $actor, ['expiry_date' => gmdate('Y-m-d', strtotime('+1 year'))]);
            self::fail('not_started → approved is not allowed');
        } catch (DomainRuleException $e) {
            self::assertSame('invalid_transition', $e->ruleCode());
        }
        self::assertSame(['not_started'], $this->trail($v));
        self::assertSame(1, $this->visaFresh($v)->recordVersion);
    }

    public function test_rejection_and_cancellation_need_a_reason(): void
    {
        $actor = $this->actor();
        $v = $this->step($this->service->create($this->candidate($actor), null, $this->details(), $actor), 'submitted', $actor);

        foreach (['rejected', 'cancelled'] as $to) {
            try {
                $this->step($v, $to, $actor);
                self::fail("{$to} needs a reason");
            } catch (ValidationException) {
                self::assertSame('submitted', $this->visaFresh($v)->status);
            }
        }

        $done = $this->service->changeStatus($v, $this->move('cancelled', ['reason' => 'Candidate withdrew']), $actor, $v->recordVersion);
        self::assertSame('cancelled', $done->status);
    }

    public function test_rejection_notifies_but_leaves_the_application_where_it_is(): void
    {
        $manager = $this->actor();
        $owner = $this->actor('recruitment');
        $c = $this->candidate($manager);
        $app = $this->applicationAt($manager, $c, 'medical_completed');
        $this->db->affectingStatement('UPDATE applications SET assigned_to = ? WHERE id = ?', [$owner->id, $app->id]);
        $v = $this->step($this->service->create($c, $this->fresh($app), $this->details(), $manager), 'submitted', $manager);

        $this->service->changeStatus($v, $this->move('rejected', ['reason' => 'Sponsor issue']), $manager, $v->recordVersion);

        self::assertSame('visa_processing', $this->fresh($app)->status);
        self::assertTrue($this->db->exists("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'visa_rejected'", [$owner->id]));
    }

    public function test_override_reopens_a_rejected_visa_with_a_reason_and_is_flagged(): void
    {
        $actor = $this->actor();
        $v = $this->step($this->service->create($this->candidate($actor), null, $this->details(), $actor), 'submitted', $actor);
        $v = $this->service->changeStatus($v, $this->move('rejected', ['reason' => 'Docs']), $actor, $v->recordVersion);

        try {
            $this->service->changeStatus($v, $this->move('under_processing'), $actor, $v->recordVersion, true);
            self::fail('override needs a reason');
        } catch (ValidationException) {
            self::assertSame('rejected', $this->visaFresh($v)->status);
        }

        $v = $this->service->changeStatus($v, $this->move('under_processing', ['reason' => 'Embassy reopened the case']), $actor, $v->recordVersion, true);
        self::assertSame('under_processing', $v->status);
        self::assertTrue($this->history->forVisa($v->id)[0]['is_override']);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='visa' AND action='status_overridden' AND record_id = ?", [$v->id]));
    }

    public function test_override_needs_its_own_permission_and_normal_moves_ignore_the_flag(): void
    {
        $manager = $this->actor();
        $visaOfficer = $this->actor('visa');
        $v = $this->service->create($this->candidate($manager), null, $this->details(), $manager);

        try {
            $this->service->changeStatus($v, $this->move('submitted', ['reason' => 'x']), $visaOfficer, $v->recordVersion, true);
            self::fail('visa officers hold change_status but not override_status');
        } catch (AuthorizationException $e) {
            self::assertSame('visa.override_status', $e->permission());
        }

        $moved = $this->service->changeStatus($v, $this->move('submitted'), $manager, $v->recordVersion, true);
        self::assertFalse($this->history->forVisa($moved->id)[0]['is_override'], 'a legal move is not an override');
    }

    public function test_stale_version_loses_the_race_for_details_and_status(): void
    {
        $actor = $this->actor();
        $v = $this->service->create($this->candidate($actor), null, $this->details(), $actor);
        $this->service->update($v, $this->details(['visa_type' => 'Work']), $actor, $v->recordVersion);

        try {
            $this->service->update($v, $this->details(['visa_type' => 'Other']), $actor, $v->recordVersion);
            self::fail('stale update');
        } catch (StaleRecordException) {
            self::assertSame('Work', $this->visaFresh($v)->visaType);
        }

        $this->expectException(StaleRecordException::class);
        $this->service->changeStatus($v, $this->move('submitted'), $actor, $v->recordVersion);
    }

    public function test_closed_visas_cannot_be_edited_and_only_unstarted_ones_deleted(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $fresh = $this->service->create($c, null, $this->details(), $actor);
        $this->service->delete($fresh, $actor);
        self::assertNull($this->repo->findById($fresh->id, $this->scope()));

        $v = $this->step($this->service->create($c, null, $this->details(), $actor), 'submitted', $actor);
        try {
            $this->service->delete($v, $actor);
            self::fail('a started visa cannot be deleted');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $closed = $this->service->changeStatus($v, $this->move('cancelled', ['reason' => 'x']), $actor, $v->recordVersion);
        $this->expectException(DomainRuleException::class);
        $this->service->update($closed, $this->details(), $actor, $closed->recordVersion);
    }

    public function test_permissions_and_branch_scope(): void
    {
        $manager = $this->actor();
        $c = $this->candidate($manager);

        foreach (['counselor', 'read_only'] as $role) {
            try {
                $this->service->create($c, null, $this->details(), $this->actor($role));
                self::fail("{$role} must not create");
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }

        $v = $this->service->create($c, null, $this->details(), $manager);
        $outsider = $this->actor('manager', $this->branchB);
        foreach ([
            fn () => $this->service->create($c, null, $this->details(), $outsider),
            fn () => $this->service->update($v, $this->details(), $outsider, $v->recordVersion),
            fn () => $this->service->changeStatus($v, $this->move('submitted'), $outsider, $v->recordVersion),
            fn () => $this->service->changeStatus($v, $this->move('submitted'), $this->actor('read_only'), $v->recordVersion),
            fn () => $this->service->delete($v, $this->actor('read_only')),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('expected AuthorizationException');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }

        self::assertNull($this->repo->findByPublicId($v->publicId, $this->scope($outsider)));
    }

    public function test_history_repository_is_append_only(): void
    {
        foreach ((new \ReflectionClass(VisaHistoryRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            self::assertDoesNotMatchRegularExpression('/^(update|delete|remove|edit|set)/i', $m->getName(), 'visa history must stay immutable');
        }
    }

    public function test_listing_filters_and_expiry_states(): void
    {
        $actor = $this->actor();
        $approve = function (string $expiry) use ($actor): VisaApplication {
            $v = $this->step($this->service->create($this->candidate($actor), null, $this->details(), $actor), 'submitted', $actor);

            return $this->step($v, 'approved', $actor, ['expiry_date' => $expiry]);
        };
        $soon = $approve(gmdate('Y-m-d', strtotime('+10 days')));
        $far = $approve(gmdate('Y-m-d', strtotime('+2 years')));
        $lapsed = $approve(gmdate('Y-m-d', strtotime('+3 days')));
        $this->db->affectingStatement('UPDATE visa_applications SET expiry_date = ? WHERE id = ?', [gmdate('Y-m-d', strtotime('-2 days')), $lapsed->id]);

        self::assertSame('expiring', $this->visaFresh($soon)->expiryState());
        self::assertSame('valid', $this->visaFresh($far)->expiryState());
        self::assertSame('expired', $this->visaFresh($lapsed)->expiryState());

        $ids = fn (array $filters): array => array_map(static fn ($x) => $x->id, $this->repo->paginate(\App\Support\ListQuery::of(['filters' => $filters]), $this->scope())->items);
        self::assertContains($soon->id, $ids(['expiry' => 'expiring']));
        self::assertNotContains($far->id, $ids(['expiry' => 'expiring']));
        self::assertSame([$lapsed->id], $ids(['expiry' => 'expired']));
        self::assertCount(3, $ids(['status' => 'approved', 'country' => 'ae']));
    }

    public function test_validator_rules(): void
    {
        $v = new VisaValidator();

        foreach ([
            fn () => $v->details(['country' => 'UAE']),
            fn () => $v->details([]),
            fn () => $v->status(['status' => 'flying']),
            fn () => $v->status(['status' => 'submitted', 'submission_date' => gmdate('Y-m-d', strtotime('+3 days'))]),
            fn () => $v->status(['status' => 'approved', 'expiry_date' => '2026-02-30']),
        ] as $case) {
            try {
                $case();
                self::fail('expected ValidationException');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
