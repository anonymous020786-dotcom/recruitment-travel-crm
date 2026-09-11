<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Exceptions\StaleRecordException;
use App\Models\Lead;
use App\Models\User;
use App\Repositories\CandidateRepository;
use App\Repositories\LeadRepository;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LeadConvertTest extends DbTestCase
{
    private LeadService $service;
    private LeadRepository $leadRepo;
    private CandidateRepository $candidateRepo;
    private int $branchA;
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
        $this->service = $this->app->get(LeadService::class);
        $this->leadRepo = $this->app->get(LeadRepository::class);
        $this->candidateRepo = $this->app->get(CandidateRepository::class);
        $this->branchA = $this->branch('LX-A');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'LX-%')";
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'LX-%'");
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
            'email' => 'lx_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function lead(User $actor, array $over = []): Lead
    {
        return $this->service->create(array_merge([
            'name' => 'Convertee', 'phone' => '96' . random_int(10000000, 99999999), 'priority' => 'medium',
        ], $over), $actor, $this->branchA, confirmedNotDuplicate: true);
    }

    public function test_convert_creates_person_candidate_and_marks_lead_converted(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor, ['name' => 'Priya Nair', 'email' => 'priya@x.com']);

        $candidate = $this->service->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $candidate->personId;

        self::assertMatchesRegularExpression('/^CAND-\d{4}-\d{6}$/', $candidate->candidateNumber);
        self::assertSame('Priya Nair', $candidate->fullName);
        self::assertSame($lead->id, $candidate->originLeadId);
        self::assertSame($this->branchA, $candidate->branchId);

        $fresh = $this->leadRepo->findById($lead->id, $this->scopeFor($actor));
        self::assertSame('converted', $fresh->statusKey);
        self::assertSame($candidate->id, $fresh->convertedCandidateId);
        self::assertNotNull($fresh->convertedAt);

        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE action='converted' AND record_id = ?", [$lead->id]));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='candidates' AND action='created' AND record_id = ?", [$candidate->id]));
    }

    public function test_second_lead_for_the_same_person_links_the_existing_candidate(): void
    {
        $actor = $this->actor('manager');
        $phone = '96' . random_int(10000000, 99999999);

        $leadOne = $this->lead($actor, ['phone' => $phone]);
        $first = $this->service->convert($leadOne, $actor, $leadOne->recordVersion);
        $this->personIds[] = $first->personId;

        $leadTwo = $this->lead($actor, ['phone' => $phone, 'name' => 'Convertee Two']);
        $second = $this->service->convert($leadTwo, $actor, $leadTwo->recordVersion);

        self::assertSame($first->id, $second->id, 'same candidate row, not a duplicate');
        self::assertSame(
            1,
            (int) $this->db->selectValue('SELECT COUNT(*) FROM candidates WHERE person_id = ?', [$first->personId]),
        );
        self::assertTrue($this->db->exists(
            'SELECT 1 FROM lead_notes WHERE lead_id = ? AND body LIKE ?',
            [$leadTwo->id, '%matched an existing candidate%'],
        ));
    }

    public function test_cannot_convert_an_already_converted_lead(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);
        $candidate = $this->service->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $candidate->personId;

        $fresh = $this->leadRepo->findById($lead->id, $this->scopeFor($actor));
        $this->expectException(AuthorizationException::class);
        $this->service->convert($fresh, $actor, $fresh->recordVersion);
    }

    public function test_requires_convert_permission(): void
    {
        $manager = $this->actor('manager');
        $lead = $this->lead($manager);
        $documentation = $this->actor('documentation'); // no leads.convert

        $this->expectException(AuthorizationException::class);
        $this->service->convert($lead, $documentation, $lead->recordVersion);
    }

    public function test_stale_version_is_rejected(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $this->expectException(StaleRecordException::class);
        $this->service->convert($lead, $actor, $lead->recordVersion + 3);
    }

    private function scopeFor(User $user): \App\Auth\BranchScope
    {
        return $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($user);
    }
}
