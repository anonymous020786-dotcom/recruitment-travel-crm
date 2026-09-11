<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Lead;
use App\Models\User;
use App\Repositories\LeadRepository;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LeadMergeTest extends DbTestCase
{
    private LeadService $service;
    private LeadRepository $repo;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];

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
        $this->repo = $this->app->get(LeadRepository::class);
        $this->branchA = $this->branch('LM-A');
        $this->branchB = $this->branch('LM-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'LM-%')";
        $this->db->affectingStatement("DELETE FROM lead_followups WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM lead_notes WHERE lead_id IN (SELECT id FROM leads WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'leads'");
        $this->db->affectingStatement("DELETE FROM notifications WHERE type LIKE 'lead_%'");
        $this->db->affectingStatement("UPDATE leads SET merged_into_id = NULL WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'LM-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%'");
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
            'email' => 'lm_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function lead(User $actor, array $over = [], ?int $branchId = null): Lead
    {
        return $this->service->create(array_merge([
            'name' => 'Dup ' . bin2hex(random_bytes(2)),
            'phone' => '97' . random_int(10000000, 99999999),
            'priority' => 'medium',
        ], $over), $actor, $branchId ?? $this->branchA, confirmedNotDuplicate: true);
    }

    public function test_merge_moves_children_soft_deletes_loser_and_audits(): void
    {
        $actor = $this->actor('manager');
        $survivor = $this->lead($actor, ['name' => 'Keeper']);
        $loser = $this->lead($actor, ['name' => 'Loser', 'email' => 'loser@x.com']);

        $this->service->addNote($loser, 'note on loser', $actor);
        $this->service->scheduleFollowup($loser, ['due_date' => gmdate('Y-m-d'), 'channel' => 'call'], $actor);

        $fresh = $this->service->mergeLeads($survivor, $loser->publicId, [], $actor, $survivor->recordVersion);

        self::assertSame($survivor->id, $fresh->id);
        // children moved
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM lead_followups WHERE lead_id = ?', [$survivor->id]));
        self::assertTrue($this->db->exists('SELECT 1 FROM lead_notes WHERE lead_id = ? AND body = ?', [$survivor->id, 'note on loser']));
        // summary note added
        self::assertTrue($this->db->exists('SELECT 1 FROM lead_notes WHERE lead_id = ? AND body LIKE ?', [$survivor->id, '%Merged in ' . $loser->leadNumber . '%']));
        // loser soft-deleted + pointer
        $row = $this->db->selectOne('SELECT deleted_at, merged_into_id FROM leads WHERE id = ?', [$loser->id]);
        self::assertNotNull($row['deleted_at']);
        self::assertSame($survivor->id, (int) $row['merged_into_id']);
        // audit on both
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE action='merged' AND record_id = ?", [$survivor->id]));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE action='merged_into' AND record_id = ?", [$loser->id]));
    }

    public function test_empty_survivor_fields_are_backfilled(): void
    {
        $actor = $this->actor('manager');
        $survivor = $this->lead($actor); // no email / city
        $loser = $this->lead($actor, ['email' => 'fill@x.com', 'city' => 'Kochi']);

        $fresh = $this->service->mergeLeads($survivor, $loser->publicId, [], $actor, $survivor->recordVersion);

        self::assertSame('fill@x.com', $fresh->email);
        self::assertSame('Kochi', $fresh->city);
    }

    public function test_take_overrides_survivor_with_loser_value(): void
    {
        $actor = $this->actor('manager');
        $survivor = $this->lead($actor, ['name' => 'Old Name', 'email' => 'old@x.com']);
        $loser = $this->lead($actor, ['name' => 'New Name', 'email' => 'new@x.com']);

        $fresh = $this->service->mergeLeads($survivor, $loser->publicId, ['name'], $actor, $survivor->recordVersion);

        self::assertSame('New Name', $fresh->name);
        self::assertSame('old@x.com', $fresh->email); // not taken
    }

    public function test_cannot_merge_into_self(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $this->expectException(ValidationException::class);
        $this->service->mergeLeads($lead, $lead->publicId, [], $actor, $lead->recordVersion);
    }

    public function test_cannot_merge_across_branches(): void
    {
        $actor = $this->actor('admin'); // org-wide, sees both branches
        $survivor = $this->lead($actor, [], $this->branchA);
        $loser = $this->lead($actor, [], $this->branchB);

        $this->expectException(ValidationException::class);
        $this->service->mergeLeads($survivor, $loser->publicId, [], $actor, $survivor->recordVersion);
    }

    public function test_requires_merge_permission(): void
    {
        $manager = $this->actor('manager');
        $survivor = $this->lead($manager);
        $loser = $this->lead($manager);

        $counselor = $this->actor('counselor'); // leads.* but not leads.merge
        $this->expectException(AuthorizationException::class);
        $this->service->mergeLeads($survivor, $loser->publicId, [], $counselor, $survivor->recordVersion);
    }

    public function test_stale_survivor_version_is_rejected(): void
    {
        $actor = $this->actor('manager');
        $survivor = $this->lead($actor);
        $loser = $this->lead($actor);

        $this->expectException(StaleRecordException::class);
        $this->service->mergeLeads($survivor, $loser->publicId, [], $actor, $survivor->recordVersion + 5);
    }

    public function test_merge_target_public_id_resolves_the_survivor(): void
    {
        $actor = $this->actor('manager');
        $survivor = $this->lead($actor);
        $loser = $this->lead($actor);
        $loserPid = $loser->publicId;

        $this->service->mergeLeads($survivor, $loserPid, [], $actor, $survivor->recordVersion);

        $scope = $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($actor);
        self::assertSame($survivor->publicId, $this->repo->mergeTargetPublicId($loserPid, $scope));
    }
}
