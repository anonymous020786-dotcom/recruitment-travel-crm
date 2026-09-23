<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Employer;
use App\Models\Job;
use App\Models\User;
use App\Repositories\JobBenefitRepository;
use App\Repositories\JobRepository;
use App\Repositories\JobRequirementRepository;
use App\Services\EmployerService;
use App\Services\JobService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\JobValidator;
use Tests\Support\DbTestCase;

final class JobServiceTest extends DbTestCase
{
    private JobService $service;
    private EmployerService $employerService;
    private JobRepository $repo;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->db->exists('SELECT 1 FROM role_permissions LIMIT 1')) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(JobService::class);
        $this->employerService = $this->app->get(EmployerService::class);
        $this->repo = $this->app->get(JobRepository::class);
        $this->branchA = $this->branch('JX-A');
        $this->branchB = $this->branch('JX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'JX-%')";
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('jobs', 'employers')");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'JX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'job:%' OR scope LIKE 'employer:%'");
        $this->db->affectingStatement("DELETE FROM skills WHERE name LIKE 'JX-Skill-%'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'jx_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function employer(User $actor, string $status = 'active'): Employer
    {
        return $this->employerService->create(['company_name' => 'Al Noor', 'country' => 'AE', 'status' => $status], $actor, $this->branchA);
    }

    private function job(User $actor, ?Employer $employer = null, array $over = []): Job
    {
        $data = (new JobValidator())->validate(array_merge([
            'title' => 'Senior Driver', 'country' => 'AE', 'vacancies' => '3',
        ], $over));

        return $this->service->create($employer ?? $this->employer($actor), $data, $actor);
    }

    private function scope(User $u): \App\Auth\BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($u);
    }

    public function test_create_makes_a_draft_with_number_slug_and_branch(): void
    {
        $actor = $this->actor();
        $j = $this->job($actor);

        self::assertMatchesRegularExpression('/^JOB-\d{4}-\d{6}$/', $j->jobNumber);
        self::assertSame('draft', $j->status);
        self::assertFalse($j->isPublic);
        self::assertSame($this->branchA, $j->branchId);
        self::assertStringStartsWith('senior-driver-job-', $j->slug);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='jobs' AND action='created' AND record_id = ?", [$j->id]));
    }

    public function test_slugs_are_unique_for_same_title(): void
    {
        $actor = $this->actor();
        $e = $this->employer($actor);

        self::assertNotSame($this->job($actor, $e)->slug, $this->job($actor, $e)->slug);
    }

    public function test_create_rejects_suspended_employer(): void
    {
        $actor = $this->actor();

        $this->expectException(DomainRuleException::class);
        $this->job($actor, $this->employer($actor, 'suspended'));
    }

    public function test_create_denies_without_permission(): void
    {
        $manager = $this->actor();
        $e = $this->employer($manager);

        $this->expectException(AuthorizationException::class);
        $this->job($this->actor('read_only'), $e);
    }

    public function test_update_changes_fields_but_keeps_slug(): void
    {
        $actor = $this->actor();
        $j = $this->job($actor);

        $u = $this->service->update($j, (new JobValidator())->validate(['title' => 'Lead Driver', 'country' => 'SA', 'vacancies' => '9']), $actor);

        self::assertSame('Lead Driver', $u->title);
        self::assertSame('SA', $u->country);
        self::assertSame($j->slug, $u->slug);
    }

    public function test_update_denies_cross_branch_manager(): void
    {
        $j = $this->job($this->actor());

        $this->expectException(AuthorizationException::class);
        $this->service->update($j, (new JobValidator())->validate(['title' => 'X', 'country' => 'AE', 'vacancies' => '1']), $this->actor('manager', $this->branchB));
    }

    public function test_out_of_scope_user_cannot_find_job(): void
    {
        $j = $this->job($this->actor());

        self::assertNull($this->repo->findByPublicId($j->publicId, $this->scope($this->actor('manager', $this->branchB))));
    }

    public function test_status_machine_allows_and_blocks_moves(): void
    {
        $actor = $this->actor();
        $j = $this->job($actor);

        $open = $this->service->changeStatus($j, 'open', $actor);
        self::assertSame('open', $open->status);

        try {
            $this->service->changeStatus($open, 'draft', $actor);
            self::fail('open -> draft must be rejected');
        } catch (DomainRuleException $e) {
            self::assertSame('invalid_transition', $e->ruleCode());
        }

        $filled = $this->service->changeStatus($open, 'filled', $actor);
        $closed = $this->service->changeStatus($filled, 'closed', $actor, 'All vacancies taken');
        self::assertSame('closed', $closed->status);

        $this->expectException(DomainRuleException::class);
        $this->service->changeStatus($closed, 'open', $actor);
    }

    public function test_draft_cannot_jump_to_filled(): void
    {
        $actor = $this->actor();

        $this->expectException(DomainRuleException::class);
        $this->service->changeStatus($this->job($actor), 'filled', $actor);
    }

    public function test_closing_or_cancelling_requires_a_reason(): void
    {
        $actor = $this->actor();
        $open = $this->service->changeStatus($this->job($actor), 'open', $actor);

        $this->expectException(ValidationException::class);
        $this->service->changeStatus($open, 'cancelled', $actor, '  ');
    }

    public function test_stale_status_move_loses_the_race(): void
    {
        $actor = $this->actor();
        $draft = $this->job($actor);
        $this->service->changeStatus($draft, 'open', $actor);

        // A second reviewer still holding the draft view tries the same move.
        $this->expectException(\App\Exceptions\StaleRecordException::class);
        $this->service->changeStatus($draft, 'cancelled', $actor, 'stale');
    }

    public function test_cannot_open_a_job_past_its_deadline(): void
    {
        $actor = $this->actor();
        $j = $this->job($actor, null, ['deadline' => '2020-01-01']);

        $this->expectException(DomainRuleException::class);
        $this->service->changeStatus($j, 'open', $actor);
    }

    public function test_publish_only_when_open_and_unpublishes_on_leaving_open(): void
    {
        $actor = $this->actor();
        $draft = $this->job($actor);

        try {
            $this->service->setPublic($draft, true, $actor);
            self::fail('draft cannot be published');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $open = $this->service->changeStatus($draft, 'open', $actor);
        $public = $this->service->setPublic($open, true, $actor);
        self::assertTrue($public->isPublic);

        $paused = $this->service->changeStatus($public, 'paused', $actor);
        self::assertFalse($paused->isPublic, 'leaving open must unpublish');
    }

    public function test_publish_denied_without_permission(): void
    {
        $manager = $this->actor();
        $open = $this->service->changeStatus($this->job($manager), 'open', $manager);

        $this->expectException(AuthorizationException::class);
        $this->service->setPublic($open, true, $this->actor('counselor')); // jobs.view only
    }

    public function test_delete_only_when_not_live(): void
    {
        $actor = $this->actor();
        $open = $this->service->changeStatus($this->job($actor), 'open', $actor);

        try {
            $this->service->delete($open, $actor);
            self::fail('open job cannot be deleted');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $draft = $this->job($actor);
        $this->service->delete($draft, $actor);
        self::assertNull($this->repo->findById($draft->id, $this->scope($actor)));
    }

    public function test_terminal_job_cannot_be_edited(): void
    {
        $actor = $this->actor();
        $cancelled = $this->service->changeStatus($this->job($actor), 'cancelled', $actor, 'client withdrew');

        $this->expectException(DomainRuleException::class);
        $this->service->addBenefit($cancelled, 'Ticket', $actor);
    }

    public function test_requirements_link_to_catalogue_skills_by_name(): void
    {
        $actor = $this->actor();
        $j = $this->job($actor);
        $skill = 'JX-Skill-' . bin2hex(random_bytes(3));
        $skillId = (int) $this->db->insertRow('skills', ['name' => $skill]);

        $linked = $this->service->addRequirement($j, ['label' => $skill, 'is_mandatory' => true, 'weight' => 5], $actor);
        $free = $this->service->addRequirement($j, ['label' => 'Own vehicle', 'is_mandatory' => false, 'weight' => 1], $actor);

        self::assertSame($skillId, $linked->skillId);
        self::assertNull($free->skillId);

        $list = $this->app->get(JobRequirementRepository::class)->forJob($j->id);
        self::assertSame($skill, $list[0]->label, 'mandatory first');

        $this->service->removeRequirement($j, $linked->id, $actor);
        self::assertCount(1, $this->app->get(JobRequirementRepository::class)->forJob($j->id));
    }

    public function test_benefits_add_validate_and_remove(): void
    {
        $actor = $this->actor();
        $j = $this->job($actor);

        $this->service->addBenefit($j, '  Annual air ticket ', $actor);
        $benefits = $this->app->get(JobBenefitRepository::class)->forJob($j->id);
        self::assertSame('Annual air ticket', $benefits[0]['label']);

        $this->service->removeBenefit($j, $benefits[0]['id'], $actor);
        self::assertSame([], $this->app->get(JobBenefitRepository::class)->forJob($j->id));

        $this->expectException(ValidationException::class);
        $this->service->addBenefit($j, '   ', $actor);
    }

    public function test_requirement_ops_reject_unknown_ids(): void
    {
        $actor = $this->actor();
        $j = $this->job($actor);

        $this->expectException(DomainRuleException::class);
        $this->service->removeRequirement($j, 999999, $actor);
    }
}
