<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\CandidateRepository;
use App\Repositories\LeadRepository;
use App\Services\CandidateService;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class CandidateServiceTest extends DbTestCase
{
    private LeadService $leadService;
    private CandidateService $service;
    private CandidateRepository $candidateRepo;
    private LeadRepository $leadRepo;
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
        if (!$this->db->exists('SELECT 1 FROM role_permissions LIMIT 1')
            || (int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->leadService = $this->app->get(LeadService::class);
        $this->service = $this->app->get(CandidateService::class);
        $this->candidateRepo = $this->app->get(CandidateRepository::class);
        $this->leadRepo = $this->app->get(LeadRepository::class);
        $this->branchA = $this->branch('CX-A');
        $this->branchB = $this->branch('CX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'CX-%')";
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM lead_notes WHERE lead_id IN (SELECT id FROM leads WHERE {$like})");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'CX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%' OR scope LIKE 'candidate:%'");
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => "Branch {$code}",
            'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}",
            'email' => 'cx_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function candidate(User $actor, array $over = []): Candidate
    {
        $lead = $this->leadService->create(array_merge([
            'name' => 'Convertee', 'phone' => '97' . random_int(10000000, 99999999), 'priority' => 'medium',
        ], $over), $actor, $this->branchA, confirmedNotDuplicate: true);

        $candidate = $this->leadService->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $candidate->personId;

        return $candidate;
    }

    private function scopeFor(User $user): \App\Auth\BranchScope
    {
        return $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($user);
    }

    public function test_update_profile_updates_person_and_candidate_columns(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor, ['name' => 'Anita Rao', 'email' => 'anita@x.com']);

        $updated = $this->service->updateProfile(
            $candidate,
            ['full_name' => 'Anita K Rao', 'email' => 'anita.k@x.com'],
            ['marital_status' => 'married', 'total_experience_years' => 3.5],
            $actor,
            $candidate->recordVersion,
        );

        self::assertSame('Anita K Rao', $updated->fullName);
        self::assertSame('anita.k@x.com', $updated->email);
        self::assertSame('married', $updated->maritalStatus);
        self::assertSame(3.5, $updated->totalExperienceYears);
        self::assertSame($candidate->recordVersion + 1, $updated->recordVersion);

        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='candidates' AND action='updated' AND record_id = ?",
            [$candidate->id],
        ));
    }

    public function test_update_profile_rejects_stale_version(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $this->expectException(StaleRecordException::class);
        $this->service->updateProfile($candidate, [], [], $actor, $candidate->recordVersion + 5);
    }

    public function test_update_profile_denies_agent_without_edit_permission(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $documentation = $this->actor('documentation'); // no candidates.edit

        $this->expectException(AuthorizationException::class);
        $this->service->updateProfile($candidate, ['full_name' => 'X'], [], $documentation, $candidate->recordVersion);
    }

    public function test_update_profile_denies_cross_branch_manager(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $otherBranchManager = $this->actor('manager', $this->branchB);

        $this->expectException(AuthorizationException::class);
        $this->service->updateProfile($candidate, ['full_name' => 'X'], [], $otherBranchManager, $candidate->recordVersion);
    }

    public function test_reassign_counselor_to_valid_user(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $counselor = $this->actor('counselor', $this->branchA);

        $updated = $this->service->reassignCounselor($candidate, $counselor->id, $actor, $candidate->recordVersion);

        self::assertSame($counselor->id, $updated->assignedCounselor);
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='candidates' AND action='counselor_assigned' AND record_id = ?",
            [$candidate->id],
        ));
    }

    public function test_reassign_counselor_unassign_with_null(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $counselor = $this->actor('counselor', $this->branchA);
        $withCounselor = $this->service->reassignCounselor($candidate, $counselor->id, $actor, $candidate->recordVersion);

        $unassigned = $this->service->reassignCounselor($withCounselor, null, $actor, $withCounselor->recordVersion);

        self::assertNull($unassigned->assignedCounselor);
    }

    public function test_reassign_counselor_rejects_out_of_branch_user(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $outsider = $this->actor('counselor', $this->branchB);

        $this->expectException(ValidationException::class);
        $this->service->reassignCounselor($candidate, $outsider->id, $actor, $candidate->recordVersion);
    }

    public function test_reassign_counselor_rejects_stale_version(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $counselor = $this->actor('counselor', $this->branchA);

        $this->expectException(StaleRecordException::class);
        $this->service->reassignCounselor($candidate, $counselor->id, $actor, $candidate->recordVersion + 5);
    }

    public function test_add_education_persists_and_audits(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $row = $this->service->addEducation($candidate, [
            'level' => 'Bachelor', 'institution' => 'City College', 'start_year' => 2015, 'end_year' => 2018,
        ], $actor);

        self::assertSame('Bachelor', $row->level);
        self::assertSame($candidate->id, $row->candidateId);
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='candidates' AND action='education_added' AND record_id = ?",
            [$candidate->id],
        ));
    }

    public function test_update_education_changes_fields(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $row = $this->service->addEducation($candidate, ['level' => 'Bachelor'], $actor);

        $updated = $this->service->updateEducation($candidate, $row->id, ['level' => 'Master', 'grade' => 'A+'], $actor);

        self::assertSame('Master', $updated->level);
        self::assertSame('A+', $updated->grade);
    }

    public function test_update_education_rejects_unknown_id(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        $this->service->updateEducation($candidate, 999999, ['level' => 'Master'], $actor);
    }

    public function test_remove_education_deletes_row(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $row = $this->service->addEducation($candidate, ['level' => 'Bachelor'], $actor);

        $this->service->removeEducation($candidate, $row->id, $actor);

        self::assertFalse($this->db->exists('SELECT 1 FROM candidate_education WHERE id = ?', [$row->id]));
    }

    public function test_remove_education_rejects_unknown_id(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        $this->service->removeEducation($candidate, 999999, $actor);
    }

    public function test_add_education_denies_cross_branch_manager(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $otherBranchManager = $this->actor('manager', $this->branchB);

        $this->expectException(AuthorizationException::class);
        $this->service->addEducation($candidate, ['level' => 'Bachelor'], $otherBranchManager);
    }

    public function test_add_experience_persists_and_audits(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $row = $this->service->addExperience($candidate, [
            'employer_name' => 'Acme Travel', 'job_title' => 'Consultant', 'is_current' => true,
        ], $actor);

        self::assertSame('Acme Travel', $row->employerName);
        self::assertTrue($row->isCurrent);
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='candidates' AND action='experience_added' AND record_id = ?",
            [$candidate->id],
        ));
    }

    public function test_update_experience_changes_fields(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $row = $this->service->addExperience($candidate, ['employer_name' => 'Acme Travel', 'job_title' => 'Consultant'], $actor);

        $updated = $this->service->updateExperience($candidate, $row->id, ['employer_name' => 'Acme Travel', 'job_title' => 'Senior Consultant'], $actor);

        self::assertSame('Senior Consultant', $updated->jobTitle);
    }

    public function test_update_experience_rejects_unknown_id(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        $this->service->updateExperience($candidate, 999999, ['employer_name' => 'X', 'job_title' => 'Y'], $actor);
    }

    public function test_remove_experience_deletes_row(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $row = $this->service->addExperience($candidate, ['employer_name' => 'Acme Travel', 'job_title' => 'Consultant'], $actor);

        $this->service->removeExperience($candidate, $row->id, $actor);

        self::assertFalse($this->db->exists('SELECT 1 FROM candidate_experience WHERE id = ?', [$row->id]));
    }

    public function test_add_experience_denies_agent_without_permission(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $documentation = $this->actor('documentation'); // no candidates.experience.manage

        $this->expectException(AuthorizationException::class);
        $this->service->addExperience($candidate, ['employer_name' => 'Acme Travel', 'job_title' => 'Consultant'], $documentation);
    }
}
