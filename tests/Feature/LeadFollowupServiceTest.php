<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScope;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Lead;
use App\Models\User;
use App\Repositories\LeadFollowupRepository;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LeadFollowupServiceTest extends DbTestCase
{
    private LeadService $service;
    private LeadFollowupRepository $repo;
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
        $this->repo = $this->app->get(LeadFollowupRepository::class);
        $this->branchA = $this->branch('LF-A');
        $this->branchB = $this->branch('LF-B');
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM lead_followups WHERE branch_id IN (SELECT id FROM branches WHERE code LIKE 'LF-%')");
        $this->db->affectingStatement("DELETE FROM lead_notes WHERE lead_id IN (SELECT id FROM leads WHERE branch_id IN (SELECT id FROM branches WHERE code LIKE 'LF-%'))");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'leads'");
        $this->db->affectingStatement("DELETE FROM notifications WHERE type LIKE 'lead_%'");
        $this->db->affectingStatement("DELETE FROM leads WHERE branch_id IN (SELECT id FROM branches WHERE code LIKE 'LF-%')");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'LF-%'");
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
            'email' => 'lf_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function lead(User $actor, ?int $assignee = null): Lead
    {
        return $this->service->create([
            'name' => 'Follow Target', 'phone' => '98' . random_int(10000000, 99999999),
            'priority' => 'medium', 'assigned_to' => $assignee,
        ], $actor, $this->branchA);
    }

    private function schedule(Lead $lead, User $actor, array $over = []): \App\Models\Followup
    {
        return $this->service->scheduleFollowup($lead, array_merge([
            'due_date' => gmdate('Y-m-d'), 'channel' => 'call',
        ], $over), $actor);
    }

    // ---- schedule --------------------------------------------------

    public function test_schedule_creates_a_pending_followup_and_audits(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $f = $this->schedule($lead, $actor, ['subject' => 'Discuss visa', 'channel' => 'whatsapp', 'due_time' => '14:30']);

        self::assertSame('pending', $f->status);
        self::assertSame('whatsapp', $f->channel);
        self::assertSame('Discuss visa', $f->subject);
        self::assertSame('14:30', $f->dueTime);
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='leads' AND action='followup_scheduled' AND record_id = ?",
            [$lead->id],
        ));
    }

    public function test_schedule_defaults_assignee_to_the_lead_owner(): void
    {
        $owner = $this->actor('counselor');
        $manager = $this->actor('manager');
        $lead = $this->lead($manager, $owner->id);

        $f = $this->schedule($lead, $manager);
        self::assertSame($owner->id, $f->assignedTo);
    }

    public function test_schedule_rejects_a_past_date(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $this->expectException(ValidationException::class);
        $this->schedule($lead, $actor, ['due_date' => gmdate('Y-m-d', time() - 86400)]);
    }

    public function test_schedule_rejects_a_bad_time_and_channel(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        try {
            $this->schedule($lead, $actor, ['due_time' => '25:99']);
            self::fail('expected time validation');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('due_time', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $this->schedule($lead, $actor, ['channel' => 'carrier-pigeon']);
    }

    public function test_schedule_requires_followups_create_permission(): void
    {
        $manager = $this->actor('manager');
        $lead = $this->lead($manager);
        $viewer = $this->actor('recruitment'); // has leads.view, not followups.create

        $this->expectException(AuthorizationException::class);
        $this->schedule($lead, $viewer);
    }

    // ---- complete -------------------------------------------------

    public function test_complete_closes_it_and_can_log_a_note_and_schedule_next(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);
        $f = $this->schedule($lead, $actor);

        $next = $this->service->completeFollowup(
            $f->id, $actor, 'Spoke, will send documents',
            logAsNote: true,
            next: ['due_date' => gmdate('Y-m-d', time() + 2 * 86400), 'channel' => 'call'],
        );

        $reloaded = $this->repo->findInScope($f->id, $this->scopeFor($actor));
        self::assertSame('completed', $reloaded->status);
        self::assertSame('Spoke, will send documents', $reloaded->outcome);
        self::assertNotNull($reloaded->completedAt);

        self::assertNotNull($next);
        self::assertSame('pending', $next->status);

        self::assertTrue($this->db->exists(
            'SELECT 1 FROM lead_notes WHERE lead_id = ? AND body LIKE ?',
            [$lead->id, '%Spoke, will send documents%'],
        ));
    }

    public function test_complete_rejects_empty_outcome_and_double_close(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);
        $f = $this->schedule($lead, $actor);

        try {
            $this->service->completeFollowup($f->id, $actor, '   ');
            self::fail('expected outcome validation');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('outcome', $e->errors());
        }

        $this->service->completeFollowup($f->id, $actor, 'done');
        $this->expectException(DomainRuleException::class);
        $this->service->completeFollowup($f->id, $actor, 'again');
    }

    public function test_cancel_marks_it_cancelled(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);
        $f = $this->schedule($lead, $actor);

        $this->service->cancelFollowup($f->id, $actor);
        self::assertSame('cancelled', $this->repo->findInScope($f->id, $this->scopeFor($actor))->status);
    }

    public function test_other_branch_cannot_touch_the_followup(): void
    {
        $actor = $this->actor('manager', $this->branchA);
        $lead = $this->lead($actor);
        $f = $this->schedule($lead, $actor);

        $outsider = $this->actor('manager', $this->branchB);
        $this->expectException(DomainRuleException::class); // not found in scope
        $this->service->cancelFollowup($f->id, $outsider);
    }

    // ---- counts --------------------------------------------------

    public function test_counts_split_overdue_today_upcoming(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor, $actor->id);

        // one due today
        $this->schedule($lead, $actor);
        // one overdue (insert directly — the service refuses past dates)
        $this->db->insertRow('lead_followups', [
            'lead_id' => $lead->id, 'assigned_to' => $actor->id, 'branch_id' => $this->branchA,
            'due_date' => gmdate('Y-m-d', time() - 3 * 86400), 'channel' => 'call', 'created_by' => $actor->id,
        ]);
        // one upcoming
        $this->schedule($lead, $actor, ['due_date' => gmdate('Y-m-d', time() + 3 * 86400)]);

        $counts = $this->repo->countsForUser($actor->id, $this->scopeFor($actor));
        self::assertSame(1, $counts['overdue']);
        self::assertSame(1, $counts['today']);
        self::assertSame(1, $counts['upcoming']);

        self::assertCount(1, $this->repo->pendingForUser($actor->id, $this->scopeFor($actor), 'overdue'));
    }

    private function scopeFor(User $user): BranchScope
    {
        return $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($user);
    }
}
