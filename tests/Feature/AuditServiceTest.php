<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditService;
use App\Models\User;
use App\Repositories\ActivityLogRepository;
use Tests\Support\DbTestCase;

final class AuditServiceTest extends DbTestCase
{
    private AuditService $audit;
    private ActivityLogRepository $repo;
    private int $recordId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ActivityLogRepository($this->db);
        $this->audit = new AuditService($this->app, $this->repo, $this->app->get(\App\Support\Logger::class));
        $this->recordId = random_int(900_000_000, 999_999_999);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM activity_logs WHERE record_id = ?', [$this->recordId]);
    }

    private function actor(): User
    {
        return new User(0, 'P', 'System', 's@s', 1, 'super_admin', null, true, true, null, false);
    }

    public function test_writes_a_row_with_diff(): void
    {
        $this->audit->log(
            'updated', 'leads', 'lead', $this->recordId,
            ['status' => 'new'], ['status' => 'contacted'],
            'manual edit', $this->actor(),
        );

        $rows = $this->repo->forRecord('lead', $this->recordId);
        self::assertCount(1, $rows);
        self::assertSame('updated', $rows[0]['action']);
        self::assertSame('leads', $rows[0]['module']);
        self::assertStringContainsString('contacted', (string) $rows[0]['new_values']);
        self::assertStringContainsString('manual edit', (string) $rows[0]['context']);
    }

    public function test_scrubs_sensitive_keys_from_snapshots(): void
    {
        $this->audit->log(
            'created', 'users', 'user', $this->recordId,
            null,
            ['email' => 'x@y.z', 'password' => 'plaintext', 'password_hash' => '$2y$...'],
        );

        $row = $this->repo->forRecord('user', $this->recordId)[0];
        self::assertStringContainsString('x@y.z', (string) $row['new_values']);
        self::assertStringNotContainsString('plaintext', (string) $row['new_values']);
        self::assertStringNotContainsString('2y$', (string) $row['new_values']);
    }

    public function test_null_actor_is_allowed_system_event(): void
    {
        $this->audit->log('ran', 'cron', 'job', $this->recordId, null, null, 'nightly');
        $row = $this->repo->forRecord('job', $this->recordId)[0];
        self::assertNull($row['user_id']);
    }

    public function test_repository_has_no_mutation_methods(): void
    {
        $methods = array_map(
            fn ($m) => $m->getName(),
            (new \ReflectionClass(ActivityLogRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        self::assertSame(
            [],
            array_filter($methods, fn ($m) => preg_match('/^(update|delete|remove|purge|truncate)/i', $m)),
            'the audit trail must be append-only',
        );
    }
}
