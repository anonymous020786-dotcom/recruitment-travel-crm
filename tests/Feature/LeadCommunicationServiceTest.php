<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ValidationException;
use App\Models\Lead;
use App\Models\User;
use App\Repositories\CommunicationLogRepository;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LeadCommunicationServiceTest extends DbTestCase
{
    private LeadService $service;
    private CommunicationLogRepository $repo;
    private int $branchA;
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
        $this->repo = $this->app->get(CommunicationLogRepository::class);
        $this->branchA = $this->branch('LC-A');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'LC-%')";
        $this->db->affectingStatement("DELETE FROM communication_logs WHERE related_type = 'lead' AND related_id IN (SELECT id FROM leads WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'leads'");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'LC-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%'");
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => "Branch {$code}",
            'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function actor(string $role = 'counselor', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}",
            'email' => 'lc_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function lead(User $actor): Lead
    {
        return $this->service->create([
            'name' => 'Comm Target', 'phone' => '96' . random_int(10000000, 99999999), 'priority' => 'medium',
        ], $actor, $this->branchA);
    }

    public function test_logs_a_communication_and_audits_it(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $log = $this->service->logCommunication($lead, [
            'channel' => 'whatsapp', 'direction' => 'inbound', 'summary' => 'They asked about visa timelines.',
        ], $actor);

        self::assertSame('whatsapp', $log->channel);
        self::assertSame('inbound', $log->direction);
        self::assertSame('They asked about visa timelines.', $log->summary);
        self::assertSame($actor->id, $log->userId);
        self::assertSame('lead', $log->relatedType);
        self::assertSame($lead->id, $log->relatedId);

        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='leads' AND action='communication_logged' AND record_id = ?",
            [$lead->id],
        ));
    }

    public function test_direction_defaults_to_outbound(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $log = $this->service->logCommunication($lead, ['channel' => 'call', 'summary' => 'Left a voicemail.'], $actor);
        self::assertSame('outbound', $log->direction);
    }

    public function test_rejects_invalid_channel_and_direction(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        try {
            $this->service->logCommunication($lead, ['channel' => 'carrier-pigeon', 'summary' => 'x'], $actor);
            self::fail('expected channel validation');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('channel', $e->errors());
        }

        try {
            $this->service->logCommunication($lead, ['channel' => 'call', 'direction' => 'sideways', 'summary' => 'x'], $actor);
            self::fail('expected direction validation');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('direction', $e->errors());
        }
    }

    public function test_rejects_empty_summary(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $this->expectException(ValidationException::class);
        $this->service->logCommunication($lead, ['channel' => 'call', 'summary' => '   '], $actor);
    }

    public function test_backdated_occurred_at_is_stored_but_future_is_rejected(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $yesterday = gmdate('Y-m-d\TH:i', time() - 86400);
        $log = $this->service->logCommunication($lead, [
            'channel' => 'meeting', 'summary' => 'Office visit.', 'occurred_at' => $yesterday,
        ], $actor);
        self::assertStringStartsWith(gmdate('Y-m-d', time() - 86400), $log->occurredAt);

        $tomorrow = gmdate('Y-m-d\TH:i', time() + 86400);
        $this->expectException(ValidationException::class);
        $this->service->logCommunication($lead, ['channel' => 'call', 'summary' => 'x', 'occurred_at' => $tomorrow], $actor);
    }

    public function test_requires_communication_log_permission(): void
    {
        $manager = $this->actor('manager');
        $lead = $this->lead($manager);
        // 'documentation' role has communication.view + .log actually — pick a role without it.
        $outsider = $this->actor('read_only');

        $this->expectException(AuthorizationException::class);
        $this->service->logCommunication($lead, ['channel' => 'call', 'summary' => 'x'], $outsider);
    }

    public function test_cross_branch_actor_is_denied(): void
    {
        $manager = $this->actor('manager', $this->branchA);
        $lead = $this->lead($manager);
        $branchB = $this->branch('LC-B');
        $otherManager = $this->actor('manager', $branchB);

        $this->expectException(AuthorizationException::class);
        $this->service->logCommunication($lead, ['channel' => 'call', 'summary' => 'x'], $otherManager);
    }

    public function test_forRecord_orders_newest_first_and_joins_user_name(): void
    {
        $actor = $this->actor('manager');
        $lead = $this->lead($actor);

        $this->service->logCommunication($lead, [
            'channel' => 'call', 'summary' => 'First', 'occurred_at' => gmdate('Y-m-d\TH:i', time() - 3600),
        ], $actor);
        $this->service->logCommunication($lead, ['channel' => 'email', 'summary' => 'Second'], $actor);

        $items = $this->repo->forRecord('lead', $lead->id);
        self::assertCount(2, $items);
        self::assertSame('Second', $items[0]->summary);
        self::assertSame('First', $items[1]->summary);
        self::assertSame($actor->name, $items[0]->userName);
        self::assertSame(2, $this->repo->countForRecord('lead', $lead->id));
    }
}
