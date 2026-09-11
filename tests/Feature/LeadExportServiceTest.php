<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\ExportRepository;
use App\Repositories\LeadRepository;
use App\Services\LeadExportService;
use App\Services\LeadService;
use App\Support\Application;
use App\Support\Config;
use App\Support\Hash;
use App\Support\ListQuery;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LeadExportServiceTest extends DbTestCase
{
    private LeadExportService $service;
    private ExportRepository $jobs;
    private LeadService $leadService;
    private int $branchA;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<string> */
    private array $writtenFiles = [];

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
        $this->service = $this->app->get(LeadExportService::class);
        $this->jobs = $this->app->get(ExportRepository::class);
        $this->leadService = $this->app->get(LeadService::class);
        $this->branchA = $this->branch('LE-A');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'LE-%')";
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'leads'");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM export_jobs WHERE requested_by IN (" . (implode(',', $this->userIds) ?: '0') . ")");
        $this->db->affectingStatement("DELETE FROM notifications WHERE type = 'export_ready'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'LE-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%'");

        $app = $this->app->get(Application::class);
        foreach ($this->writtenFiles as $path) {
            @unlink($app->basePath($path));
        }
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
            'email' => 'le_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    public function test_request_rejects_when_nothing_matches(): void
    {
        $actor = $this->actor('manager');

        $this->expectException(ValidationException::class);
        $this->service->request(ListQuery::of(['filters' => ['status' => 'converted']]), $actor);
    }

    public function test_request_rejects_when_over_the_row_cap(): void
    {
        $actor = $this->actor('manager');
        $this->leadService->create(['name' => 'Cap Lead', 'phone' => '9911100001', 'priority' => 'medium'], $actor, $this->branchA);
        $this->app->get(Config::class)->set('import_export.leads.export.max_rows', 0);

        $this->expectException(ValidationException::class);
        $this->service->request(ListQuery::of([]), $actor);
    }

    public function test_request_then_process_writes_a_csv_and_notifies(): void
    {
        $actor = $this->actor('manager');
        $this->leadService->create(['name' => 'Export Me', 'phone' => '9911100002', 'email' => 'exp@x.com', 'priority' => 'high'], $actor, $this->branchA);

        $queued = $this->service->request(ListQuery::of([]), $actor);
        $claimed = $this->jobs->claimPending(5);
        $job = null;
        foreach ($claimed as $c) {
            if ((int) $c['id'] === $queued['id']) {
                $job = $c;
            }
        }
        self::assertNotNull($job, 'the queued job should be claimable');

        $rowCount = $this->service->process($job);
        self::assertGreaterThanOrEqual(1, $rowCount);

        $completed = $this->jobs->findByPublicId($queued['publicId']);
        self::assertSame('completed', $completed->status);
        self::assertTrue($completed->isReady());
        $this->writtenFiles[] = $completed->storagePath;

        $absolute = $this->app->basePath($completed->storagePath);
        self::assertFileExists($absolute);
        $csv = file_get_contents($absolute);
        self::assertStringContainsString('Export Me', $csv);
        self::assertStringContainsString('exp@x.com', $csv);

        self::assertTrue($this->db->exists(
            "SELECT 1 FROM notifications WHERE user_id = ? AND type = 'export_ready'",
            [$actor->id],
        ));
    }

    public function test_export_only_includes_rows_within_the_requesters_scope(): void
    {
        $actor = $this->actor('manager');
        $branchB = $this->branch('LE-B');
        $outsider = $this->actor('manager', $branchB);

        $this->leadService->create(['name' => 'In Scope', 'phone' => '9911100010', 'priority' => 'medium'], $actor, $this->branchA);
        $this->leadService->create(['name' => 'Out Of Scope', 'phone' => '9911100011', 'priority' => 'medium'], $outsider, $branchB);

        $queued = $this->service->request(ListQuery::of(['search' => 'Of Scope']), $outsider);
        $job = null;
        foreach ($this->jobs->claimPending(5) as $c) {
            if ((int) $c['id'] === $queued['id']) {
                $job = $c;
            }
        }
        self::assertNotNull($job);
        $this->service->process($job);

        $completed = $this->jobs->findByPublicId($queued['publicId']);
        $this->writtenFiles[] = $completed->storagePath;
        $csv = file_get_contents($this->app->basePath($completed->storagePath));
        self::assertStringContainsString('Out Of Scope', $csv);
        self::assertStringNotContainsString('In Scope', $csv);
        // branchB, its lead, and $outsider are all cleaned up by tearDown()
        // (branchB's code also matches 'LE-%', and $outsider is in $userIds).
    }

    public function test_csv_cells_are_sanitised_against_formula_injection(): void
    {
        $actor = $this->actor('manager');
        $this->leadService->create([
            'name' => '=SUM(1+1)', 'phone' => '9911100020', 'priority' => 'medium',
            'campaign' => '@cmd|calc',
        ], $actor, $this->branchA);

        $queued = $this->service->request(ListQuery::of([]), $actor);
        $job = null;
        foreach ($this->jobs->claimPending(5) as $c) {
            if ((int) $c['id'] === $queued['id']) {
                $job = $c;
            }
        }
        $this->service->process($job);

        $completed = $this->jobs->findByPublicId($queued['publicId']);
        $this->writtenFiles[] = $completed->storagePath;
        $csv = file_get_contents($this->app->basePath($completed->storagePath));

        self::assertStringNotContainsString(",=SUM", $csv);
        self::assertStringContainsString("'=SUM(1+1)", $csv);
        self::assertStringContainsString("'@cmd|calc", $csv);
    }
}
