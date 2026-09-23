<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Employer;
use App\Models\Job;
use App\Models\User;
use App\Repositories\ApplicationHistoryRepository;
use App\Repositories\ApplicationRepository;
use App\Services\ApplicationService;
use App\Services\CandidateService;
use App\Services\EmployerService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\JobValidator;
use Tests\Support\DbTestCase;

final class ApplicationServiceTest extends DbTestCase
{
    private ApplicationService $service;
    private ApplicationRepository $repo;
    private ApplicationHistoryRepository $history;
    private JobService $jobService;
    private EmployerService $employerService;
    private LeadService $leadService;
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
        if (!$this->db->exists('SELECT 1 FROM role_permissions LIMIT 1') || (int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(ApplicationService::class);
        $this->repo = $this->app->get(ApplicationRepository::class);
        $this->history = $this->app->get(ApplicationHistoryRepository::class);
        $this->jobService = $this->app->get(JobService::class);
        $this->employerService = $this->app->get(EmployerService::class);
        $this->leadService = $this->app->get(LeadService::class);
        $this->branchA = $this->branch('AX-A');
        $this->branchB = $this->branch('AX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'AX-%')";
        $this->db->affectingStatement("DELETE FROM interviews WHERE application_id IN (SELECT id FROM applications WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('applications', 'jobs', 'employers', 'leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        if ($this->personIds !== []) {
            $ph = implode(',', array_fill(0, count($this->personIds), '?'));
            $this->db->affectingStatement("DELETE FROM persons WHERE id IN ({$ph})", $this->personIds);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'AX-%'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'ax_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function employer(User $actor): Employer
    {
        return $this->employerService->create(['company_name' => 'Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
    }

    private function openJob(User $actor, ?Employer $e = null, array $over = []): Job
    {
        $job = $this->jobService->create($e ?? $this->employer($actor), (new JobValidator())->validate(array_merge([
            'title' => 'Driver', 'country' => 'AE', 'vacancies' => '2',
        ], $over)), $actor);

        return $this->jobService->changeStatus($job, 'open', $actor);
    }

    private function candidate(User $actor, string $name = 'Cand'): Candidate
    {
        $lead = $this->leadService->create([
            'name' => $name, 'phone' => '94' . random_int(10000000, 99999999), 'priority' => 'medium',
        ], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $this->leadService->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    private function apply(User $actor, ?Job $job = null, ?Candidate $c = null): Application
    {
        return $this->service->create($c ?? $this->candidate($actor), $job ?? $this->openJob($actor), $actor);
    }

    private function stage(Candidate $c): string
    {
        return (string) $this->db->selectValue('SELECT stage FROM candidates WHERE id = ?', [$c->id]);
    }

    /** @return list<string> to-statuses oldest first */
    private function trail(Application $a): array
    {
        return array_reverse(array_map(static fn (array $h): string => $h['to'], $this->history->forApplication($a->id)));
    }

    public function test_create_snapshots_score_history_stage_and_audit(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->service->create($c, $this->openJob($actor), $actor);

        self::assertMatchesRegularExpression('/^APP-\d{4}-\d{6}$/', $app->applicationNumber);
        self::assertSame('applied', $app->status);
        self::assertNotNull($app->matchScore);
        self::assertIsArray($app->matchBreakdown);
        self::assertArrayHasKey('criteria', $app->matchBreakdown);
        self::assertSame($c->branchId, $app->branchId);
        self::assertSame(['applied'], $this->trail($app));
        self::assertSame('applied', $this->stage($c));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='applications' AND action='created' AND record_id = ?", [$app->id]));
    }

    public function test_cannot_apply_twice_to_the_same_job(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $job = $this->openJob($actor);
        $this->service->create($c, $job, $actor);

        $this->expectException(DomainRuleException::class);
        $this->service->create($c, $job, $actor);
    }

    public function test_cannot_apply_to_a_job_that_is_not_open(): void
    {
        $actor = $this->actor();
        $draft = $this->jobService->create($this->employer($actor), (new JobValidator())->validate(['title' => 'X', 'country' => 'AE', 'vacancies' => '1']), $actor);

        $this->expectException(DomainRuleException::class);
        $this->service->create($this->candidate($actor), $draft, $actor);
    }

    public function test_cannot_apply_after_the_deadline(): void
    {
        $actor = $this->actor();
        $job = $this->openJob($actor, null, ['deadline' => gmdate('Y-m-d', strtotime('+1 day'))]);
        $this->db->affectingStatement('UPDATE jobs SET deadline = ? WHERE id = ?', ['2020-01-01', $job->id]);
        $stale = $this->app->get(\App\Repositories\JobRepository::class)->findById($job->id, $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($actor));

        $this->expectException(DomainRuleException::class);
        $this->service->create($this->candidate($actor), $stale, $actor);
    }

    public function test_cannot_apply_for_an_inactive_candidate(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $this->db->affectingStatement('UPDATE candidates SET is_active = 0 WHERE id = ?', [$c->id]);
        $inactive = $this->app->get(\App\Repositories\CandidateRepository::class)->findById($c->id, $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($actor));

        $this->expectException(DomainRuleException::class);
        $this->service->create($inactive, $this->openJob($actor), $actor);
    }

    public function test_create_denied_without_permission_and_across_branches(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $job = $this->openJob($actor);

        try {
            $this->service->create($c, $job, $this->actor('read_only'));
            self::fail('read_only cannot create');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $this->expectException(AuthorizationException::class);
        $this->service->create($c, $job, $this->actor('manager', $this->branchB));
    }

    public function test_valid_moves_build_an_ordered_history_and_advance_the_stage(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->service->create($c, $this->openJob($actor), $actor);

        $app = $this->service->changeStatus($app, 'shortlisted', $actor, $app->recordVersion);
        $app = $this->service->changeStatus($app, 'interview_scheduled', $actor, $app->recordVersion);

        self::assertSame(['applied', 'shortlisted', 'interview_scheduled'], $this->trail($app));
        self::assertSame('interview_scheduled', $this->stage($c));
        self::assertSame(3, $app->recordVersion);
    }

    public function test_arbitrary_transition_is_rejected_and_writes_no_history(): void
    {
        $actor = $this->actor();
        $app = $this->apply($actor);

        try {
            $this->service->changeStatus($app, 'offer_received', $actor, $app->recordVersion);
            self::fail('applied → offer_received must be rejected');
        } catch (DomainRuleException $e) {
            self::assertSame('invalid_transition', $e->ruleCode());
        }

        self::assertSame(['applied'], $this->trail($app));
        self::assertSame('applied', $this->repo->findById($app->id, $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($actor))->status);
    }

    public function test_reject_and_cancel_need_a_reason_and_cancel_records_it(): void
    {
        $actor = $this->actor();
        $app = $this->apply($actor);

        try {
            $this->service->changeStatus($app, 'rejected', $actor, $app->recordVersion, '  ');
            self::fail('reason required');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $cancelled = $this->service->changeStatus($app, 'cancelled', $actor, $app->recordVersion, 'Candidate withdrew');
        self::assertSame('cancelled', $cancelled->status);
        self::assertSame('Candidate withdrew', $cancelled->cancelReason);
        self::assertNotNull($cancelled->closedAt);
    }

    public function test_rejected_stays_closed_without_override_and_stage_falls_back(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->service->create($c, $this->openJob($actor), $actor);
        $rejected = $this->service->changeStatus($app, 'rejected', $actor, $app->recordVersion, 'Not suitable');

        self::assertSame('registered', $this->stage($c), 'no live application left');

        $this->expectException(DomainRuleException::class);
        $this->service->changeStatus($rejected, 'applied', $actor, $rejected->recordVersion, 'reopen');
    }

    public function test_override_reopens_with_reason_and_is_flagged_in_history(): void
    {
        $actor = $this->actor(); // manager holds applications.override_status
        $app = $this->apply($actor);
        $rejected = $this->service->changeStatus($app, 'rejected', $actor, $app->recordVersion, 'Not suitable');

        try {
            $this->service->changeStatus($rejected, 'shortlisted', $actor, $rejected->recordVersion, null, true);
            self::fail('override needs a reason');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $reopened = $this->service->changeStatus($rejected, 'shortlisted', $actor, $rejected->recordVersion, 'Client asked to reconsider', true);

        self::assertSame('shortlisted', $reopened->status);
        self::assertNull($reopened->closedAt, 'reopening clears closed_at');
        $latest = $this->history->forApplication($app->id)[0];
        self::assertTrue($latest['is_override']);
        self::assertSame('Client asked to reconsider', $latest['reason']);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='applications' AND action='status_overridden' AND record_id = ?", [$app->id]));
    }

    public function test_override_flag_on_a_legal_move_is_not_recorded_as_an_override(): void
    {
        $actor = $this->actor();
        $app = $this->apply($actor);

        $this->service->changeStatus($app, 'shortlisted', $actor, $app->recordVersion, null, true);

        self::assertFalse($this->history->forApplication($app->id)[0]['is_override']);
    }

    public function test_override_denied_without_override_permission(): void
    {
        $manager = $this->actor();
        $app = $this->apply($manager);
        $rejected = $this->service->changeStatus($app, 'rejected', $manager, $app->recordVersion, 'No');

        $this->expectException(AuthorizationException::class);
        $this->service->changeStatus($rejected, 'shortlisted', $this->actor('recruitment'), $rejected->recordVersion, 'why', true);
    }

    public function test_status_change_denied_without_change_permission(): void
    {
        $manager = $this->actor();
        $app = $this->apply($manager);

        $this->expectException(AuthorizationException::class);
        $this->service->changeStatus($app, 'shortlisted', $this->actor('counselor'), $app->recordVersion);
    }

    public function test_stale_version_loses_the_race(): void
    {
        $actor = $this->actor();
        $app = $this->apply($actor);
        $this->service->changeStatus($app, 'shortlisted', $actor, $app->recordVersion);

        $this->expectException(StaleRecordException::class);
        $this->service->changeStatus($app, 'documents_submitted', $actor, $app->recordVersion);
    }

    public function test_stage_follows_the_most_advanced_live_application(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $a1 = $this->service->create($c, $this->openJob($actor), $actor);
        $a2 = $this->service->create($c, $this->openJob($actor), $actor);
        $this->service->changeStatus($a2, 'shortlisted', $actor, $a2->recordVersion);
        self::assertSame('shortlisted', $this->stage($c));

        $a2 = $this->repo->findById($a2->id, $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($actor));
        $this->service->changeStatus($a2, 'rejected', $actor, $a2->recordVersion, 'Nope');
        self::assertSame('applied', $this->stage($c), 'falls back to the remaining live application');
        self::assertNotNull($a1);
    }

    public function test_history_repository_is_append_only(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(ApplicationHistoryRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        foreach ($methods as $m) {
            self::assertDoesNotMatchRegularExpression('/^(update|delete|remove|edit|set)/i', $m, "history must stay immutable, found {$m}()");
        }
    }

    public function test_out_of_scope_user_cannot_find_the_application(): void
    {
        $app = $this->apply($this->actor());
        $outsider = $this->actor('manager', $this->branchB);

        self::assertNull($this->repo->findByPublicId($app->publicId, $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($outsider)));
    }
}
