<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScope;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\LeadRepository;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LeadServiceTest extends DbTestCase
{
    private LeadService $service;
    private LeadRepository $repo;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->exists('SELECT 1 FROM role_permissions LIMIT 1')
            || (int) $this->db->selectValue("SELECT COUNT(*) FROM lead_statuses") === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }

        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }

        $this->service = $this->app->get(LeadService::class);
        $this->repo = $this->app->get(LeadRepository::class);

        $this->branchA = $this->branch('LS-A');
        $this->branchB = $this->branch('LS-B');
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM lead_notes WHERE lead_id IN (SELECT id FROM leads WHERE lead_number LIKE 'LEAD-%' AND branch_id IN (SELECT id FROM branches WHERE code LIKE 'LS-%'))");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'leads'");
        $this->db->affectingStatement("DELETE FROM notifications WHERE type LIKE 'lead_%'");
        $this->db->affectingStatement("DELETE FROM leads WHERE branch_id IN (SELECT id FROM branches WHERE code LIKE 'LS-%')");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'LS-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%'");
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(),
            'name' => "Branch {$code}",
            'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(),
            'name' => "Actor {$role}",
            'email' => 'ls_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role],
            'primary_branch_id' => $branchId,
            'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Asha Rao',
            'phone' => '9812345678',
            'priority' => 'medium',
        ], $overrides);
    }

    // ---- create ------------------------------------------------------

    public function test_create_generates_number_and_audits(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->service->create($this->payload(), $actor, $this->branchA);

        self::assertMatchesRegularExpression('/^LEAD-\d{4}-\d{6}$/', $lead->leadNumber);
        self::assertSame('new', $lead->statusKey);
        self::assertSame($actor->id, $lead->createdBy);
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='leads' AND action='created' AND record_id = ?",
            [$lead->id],
        ));
    }

    public function test_create_blocks_unconfirmed_duplicate_then_allows_confirmed(): void
    {
        $actor = $this->actor('manager');
        $this->service->create($this->payload(['phone' => '9800011122']), $actor, $this->branchA);

        try {
            $this->service->create($this->payload(['name' => 'Someone Else', 'phone' => '9800011122']), $actor, $this->branchA);
            self::fail('expected duplicate exception');
        } catch (DomainRuleException $e) {
            self::assertSame(DomainRuleException::DUPLICATE_LEAD, $e->ruleCode());
            self::assertNotEmpty($e->context()['duplicates']);
        }

        $lead = $this->service->create(
            $this->payload(['name' => 'Someone Else', 'phone' => '9800011122']),
            $actor,
            $this->branchA,
            confirmedNotDuplicate: true,
        );
        self::assertNotNull($lead->id);
    }

    public function test_create_rejects_branch_outside_scope(): void
    {
        $actor = $this->actor('manager', $this->branchA);
        $this->expectException(ValidationException::class);
        $this->service->create($this->payload(), $actor, $this->branchB);
    }

    public function test_create_rejects_assignee_from_another_branch(): void
    {
        $actor = $this->actor('manager', $this->branchA);
        $other = $this->actor('counselor', $this->branchB);

        $this->expectException(ValidationException::class);
        $this->service->create($this->payload(['assigned_to' => $other->id]), $actor, $this->branchA);
    }

    public function test_counselor_without_create_permission_is_denied(): void
    {
        $actor = $this->actor('read_only', $this->branchA);
        $this->expectException(AuthorizationException::class);
        $this->service->create($this->payload(), $actor, $this->branchA);
    }

    // ---- update / optimistic lock ---------------------------------

    public function test_update_applies_diff_and_bumps_version(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->service->create($this->payload(), $actor, $this->branchA);

        $updated = $this->service->update($lead, $this->payload(['name' => 'Asha R.', 'priority' => 'high']), $actor, $lead->recordVersion);
        self::assertSame('Asha R.', $updated->name);
        self::assertSame('high', $updated->priority);
        self::assertSame($lead->recordVersion + 1, $updated->recordVersion);
    }

    public function test_update_with_stale_version_throws(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->service->create($this->payload(), $actor, $this->branchA);
        $this->service->update($lead, $this->payload(['name' => 'v2']), $actor, $lead->recordVersion);

        $this->expectException(StaleRecordException::class);
        $this->service->update($lead, $this->payload(['name' => 'v3']), $actor, $lead->recordVersion);
    }

    // ---- assign --------------------------------------------------

    public function test_assign_notifies_the_new_owner(): void
    {
        $actor = $this->actor('manager');
        $counselor = $this->actor('counselor', $this->branchA);
        $lead = $this->service->create($this->payload(), $actor, $this->branchA);

        $this->service->assign($lead, $counselor->id, $actor, $lead->recordVersion);

        self::assertTrue($this->db->exists(
            "SELECT 1 FROM notifications WHERE user_id = ? AND type = 'lead_assigned'",
            [$counselor->id],
        ));
    }

    // ---- status ------------------------------------------------

    public function test_status_change_follows_the_machine(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->service->create($this->payload(), $actor, $this->branchA);

        $lead = $this->service->changeStatus($lead, 'contacted', $actor, $lead->recordVersion);
        self::assertSame('contacted', $lead->statusKey);

        $this->expectException(DomainRuleException::class);
        $this->service->changeStatus($lead, 'converted', $actor, $lead->recordVersion);
    }

    public function test_status_cannot_be_manually_set_to_converted(): void
    {
        $manager = $this->actor('manager');
        $lead = $this->service->create($this->payload(), $manager, $this->branchA);
        $lead = $this->service->changeStatus($lead, 'contacted', $manager, $lead->recordVersion);
        $lead = $this->service->changeStatus($lead, 'interested', $manager, $lead->recordVersion);

        // "interested -> converted" is in the machine, but the service forces
        // the convert() workflow instead.
        $this->expectException(DomainRuleException::class);
        $this->service->changeStatus($lead, 'converted', $manager, $lead->recordVersion);
    }

    public function test_lost_status_requires_a_reason(): void
    {
        $manager = $this->actor('manager');
        $lead = $this->service->create($this->payload(), $manager, $this->branchA);

        try {
            $this->service->changeStatus($lead, 'lost', $manager, $lead->recordVersion);
            self::fail('expected reason-required error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reason', $e->errors());
        }

        $lead = $this->service->changeStatus($lead, 'lost', $manager, $lead->recordVersion, 'wrong number');
        self::assertSame('lost', $lead->statusKey);
        self::assertSame('wrong number', $this->repo->findById($lead->id, BranchScope::orgWide())->lostReason);
    }

    // ---- notes / delete -----------------------------------------

    public function test_add_note(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->service->create($this->payload(), $actor, $this->branchA);

        $this->service->addNote($lead, '  Called, will decide next week  ', $actor);

        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM lead_notes WHERE lead_id = ?', [$lead->id]));
    }

    public function test_delete_soft_deletes_and_blocks_converted(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->service->create($this->payload(), $actor, $this->branchA);

        $this->service->delete($lead, $actor, $lead->recordVersion);
        self::assertNull($this->repo->findById($lead->id, BranchScope::orgWide()));

        // converted lead cannot be deleted
        $lead2 = $this->service->create($this->payload(['phone' => '9700000001']), $actor, $this->branchA);
        $this->db->affectingStatement('UPDATE leads SET converted_candidate_id = 1 WHERE id = ?', [$lead2->id]);
        $lead2 = $this->repo->findById($lead2->id, BranchScope::orgWide());

        $this->expectException(\Throwable::class);
        $this->service->delete($lead2, $actor, $lead2->recordVersion);
    }
}
