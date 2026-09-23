<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Models\Candidate;
use App\Models\Employer;
use App\Models\Job;
use App\Models\User;
use App\Services\CandidateService;
use App\Services\EmployerService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\MatchService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\JobValidator;
use Tests\Support\DbTestCase;

final class MatchServiceTest extends DbTestCase
{
    private MatchService $service;
    private JobService $jobService;
    private EmployerService $employerService;
    private LeadService $leadService;
    private CandidateService $candidateService;
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
        $this->service = $this->app->get(MatchService::class);
        $this->jobService = $this->app->get(JobService::class);
        $this->employerService = $this->app->get(EmployerService::class);
        $this->leadService = $this->app->get(LeadService::class);
        $this->candidateService = $this->app->get(CandidateService::class);
        $this->branchA = $this->branch('MX-A');
        $this->branchB = $this->branch('MX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'MX-%')";
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('jobs', 'employers', 'leads', 'candidates')");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'MX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'job:%' OR scope LIKE 'employer:%' OR scope LIKE 'lead:%' OR scope LIKE 'candidate:%'");
        $this->db->affectingStatement("DELETE FROM skills WHERE name LIKE 'MX-Skill-%'");
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

    private function employer(User $actor): Employer
    {
        return $this->employerService->create(['company_name' => 'Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
    }

    private function openJob(User $actor, Employer $e, array $over = []): Job
    {
        $job = $this->jobService->create($e, (new JobValidator())->validate(array_merge([
            'title' => 'Forklift Operator', 'country' => 'AE', 'vacancies' => '2', 'experience_required' => '3 years',
        ], $over)), $actor);

        return $this->jobService->changeStatus($job, 'open', $actor);
    }

    private function candidate(User $actor, string $name, ?int $branchId = null): Candidate
    {
        $lead = $this->leadService->create([
            'name' => $name, 'phone' => '95' . random_int(10000000, 99999999), 'priority' => 'medium',
        ], $actor, $branchId ?? $this->branchA, confirmedNotDuplicate: true);
        $candidate = $this->leadService->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $candidate->personId;

        return $candidate;
    }

    public function test_ranks_stronger_candidate_first_and_explains_why(): void
    {
        $actor = $this->actor();
        $skill = 'MX-Skill-' . bin2hex(random_bytes(3));
        $job = $this->openJob($actor, $this->employer($actor));
        $this->db->insertRow('skills', ['name' => $skill]);
        $this->jobService->addRequirement($job, ['label' => $skill, 'is_mandatory' => true, 'weight' => 5], $actor);

        $strong = $this->candidate($actor, 'Strong');
        $this->candidateService->addSkill($strong, ['skill_name' => $skill, 'proficiency' => 'advanced', 'years' => 4.0], $actor);
        $this->candidateService->updateProfile($strong, [], ['total_experience_years' => 5.0], $actor, $strong->recordVersion);
        $weak = $this->candidate($actor, 'Weak');

        $rows = $this->service->rankCandidatesForJob($job, $actor);

        self::assertSame('Strong', $rows[0]['candidate']['name']);
        self::assertSame('Weak', $rows[1]['candidate']['name']);
        self::assertGreaterThan($rows[1]['result']->score, $rows[0]['result']->score);
        self::assertTrue($rows[0]['result']->eligible);
        self::assertFalse($rows[1]['result']->eligible, 'lacks the mandatory skill');
        self::assertSame([$skill], $rows[1]['result']->missingMandatory);
        self::assertNotEmpty($rows[1]['result']->missing());
    }

    public function test_eligible_candidates_outrank_higher_scoring_ineligible_ones(): void
    {
        $actor = $this->actor();
        $skill = 'MX-Skill-' . bin2hex(random_bytes(3));
        $job = $this->openJob($actor, $this->employer($actor), ['experience_required' => '']);
        $this->db->insertRow('skills', ['name' => $skill]);
        $this->jobService->addRequirement($job, ['label' => $skill, 'is_mandatory' => true, 'weight' => 1], $actor);

        $ineligible = $this->candidate($actor, 'Ineligible');
        $eligible = $this->candidate($actor, 'Eligible');
        $this->candidateService->addSkill($eligible, ['skill_name' => $skill], $actor);

        $rows = $this->service->rankCandidatesForJob($job, $actor);

        self::assertSame('Eligible', $rows[0]['candidate']['name']);
        self::assertSame('Ineligible', $rows[1]['candidate']['name']);
    }

    public function test_pool_is_branch_scoped(): void
    {
        $actor = $this->actor();
        $job = $this->openJob($actor, $this->employer($actor));
        $otherActor = $this->actor('manager', $this->branchB);
        $this->candidate($otherActor, 'Elsewhere', $this->branchB);
        $here = $this->candidate($actor, 'Here');

        $names = array_map(static fn (array $r): string => $r['candidate']['name'], $this->service->rankCandidatesForJob($job, $actor));

        self::assertSame(['Here'], $names);
        self::assertNotNull($here);
    }

    public function test_result_limit_is_applied(): void
    {
        $actor = $this->actor();
        $job = $this->openJob($actor, $this->employer($actor));
        $this->candidate($actor, 'One');
        $this->candidate($actor, 'Two');
        $this->candidate($actor, 'Three');

        self::assertCount(2, $this->service->rankCandidatesForJob($job, $actor, 2));
    }

    public function test_denies_actor_without_match_permission(): void
    {
        $actor = $this->actor();
        $job = $this->openJob($actor, $this->employer($actor));

        $this->expectException(AuthorizationException::class);
        $this->service->rankCandidatesForJob($job, $this->actor('counselor')); // jobs.view but no jobs.match
    }

    public function test_denies_cross_branch_manager_for_job(): void
    {
        $actor = $this->actor();
        $job = $this->openJob($actor, $this->employer($actor));

        $this->expectException(AuthorizationException::class);
        $this->service->rankCandidatesForJob($job, $this->actor('manager', $this->branchB));
    }

    public function test_jobs_for_candidate_only_include_open_jobs_in_scope(): void
    {
        $actor = $this->actor();
        $employer = $this->employer($actor);
        $open = $this->openJob($actor, $employer, ['title' => 'Open Job']);
        $this->jobService->create($employer, (new JobValidator())->validate(['title' => 'Draft Job', 'country' => 'AE', 'vacancies' => '1']), $actor);
        $paused = $this->openJob($actor, $employer, ['title' => 'Paused Job']);
        $this->jobService->changeStatus($paused, 'paused', $actor);
        $candidate = $this->candidate($actor, 'Someone');

        $titles = array_map(static fn (array $r): string => $r['job']->title, $this->service->rankJobsForCandidate($candidate, $actor));

        self::assertSame([$open->title], $titles);
    }

    public function test_jobs_for_candidate_denied_without_permission(): void
    {
        $actor = $this->actor();
        $candidate = $this->candidate($actor, 'Someone');

        $this->expectException(AuthorizationException::class);
        $this->service->rankJobsForCandidate($candidate, $this->actor('counselor'));
    }

    public function test_inactive_candidates_are_not_in_the_pool(): void
    {
        $actor = $this->actor();
        $job = $this->openJob($actor, $this->employer($actor));
        $c = $this->candidate($actor, 'Retired');
        $this->db->affectingStatement('UPDATE candidates SET is_active = 0 WHERE id = ?', [$c->id]);

        self::assertSame([], $this->service->rankCandidatesForJob($job, $actor));
    }
}
