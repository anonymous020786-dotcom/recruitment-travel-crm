<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\ValidationException;
use App\Models\ImportBatch;
use App\Models\User;
use App\Repositories\ImportRepository;
use App\Repositories\LeadRepository;
use App\Services\LeadImportService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LeadImportServiceTest extends DbTestCase
{
    private LeadImportService $service;
    private ImportRepository $repo;
    private LeadRepository $leads;
    private int $branchA;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<string> */
    private array $tmpFiles = [];

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
        $this->service = $this->app->get(LeadImportService::class);
        $this->repo = $this->app->get(ImportRepository::class);
        $this->leads = $this->app->get(LeadRepository::class);
        $this->branchA = $this->branch('LI-A');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'LI-%')";
        $this->db->affectingStatement("DELETE FROM lead_notes WHERE lead_id IN (SELECT id FROM leads WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'leads'");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM import_batches WHERE {$like}");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'LI-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%'");

        foreach ($this->tmpFiles as $path) {
            if (str_starts_with($path, sys_get_temp_dir())) {
                @unlink($path);
            } else {
                @unlink($this->app->basePath($path));
            }
        }
        // Any generated report next to the stored CSVs, matched by our test batches.
        foreach (glob($this->app->basePath('storage/imports') . '/*.csv') ?: [] as $f) {
            if (filemtime($f) >= (time() - 60)) {
                @unlink($f);
            }
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
            'email' => 'li_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    /** @param list<string> $lines */
    private function file(array $lines): array
    {
        $path = tempnam(sys_get_temp_dir(), 'leadimport');
        file_put_contents($path, implode("\r\n", $lines) . "\r\n");
        $this->tmpFiles[] = $path;

        return ['name' => 'leads.csv', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }

    private function scopeFor(User $user): \App\Auth\BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($user);
    }

    public function test_stage_suggests_a_mapping_from_headers_and_stores_rows(): void
    {
        $actor = $this->actor('manager');
        $file = $this->file([
            'Name,Phone,Email',
            'Asha Rao,9812345678,asha@x.com',
            'Bilal Khan,9812345679,bilal@x.com',
        ]);

        $staged = $this->service->stage($file, $actor, $this->branchA);
        $batch = $staged['batch'];

        self::assertSame('previewed', $batch->status);
        self::assertSame(2, $batch->totalRows);
        self::assertSame(['Name', 'Phone', 'Email'], $batch->headers);
        self::assertSame(['0' => 'name', '1' => 'phone', '2' => 'email'], $batch->mapping);
        self::assertNotEmpty($this->repo->sampleRows($batch->id));
    }

    public function test_confirm_imports_valid_rows_and_fails_invalid_ones(): void
    {
        $actor = $this->actor('manager');
        $file = $this->file([
            'Name,Phone',
            'Good Lead,9812340001',
            ',9812340002',          // missing required name -> failed
        ]);
        $batch = $this->service->stage($file, $actor, $this->branchA)['batch'];

        $result = $this->service->confirm($batch, $batch->mapping, false, $actor);

        self::assertSame('completed', $result->status);
        self::assertSame(1, $result->importedRows);
        self::assertSame(0, $result->skippedRows);
        self::assertSame(1, $result->failedRows);
        self::assertNotNull($result->reportPath);
        self::assertTrue($this->db->exists('SELECT 1 FROM leads WHERE name = ?', ['Good Lead']));

        $problems = $this->repo->problemRows($batch->id);
        self::assertCount(1, $problems);
        self::assertSame('failed', $problems[0]['status']);
    }

    public function test_duplicate_rows_are_skipped_unless_import_duplicates_is_set(): void
    {
        $actor = $this->actor('manager');
        // Seed an existing lead with this phone.
        $this->db->insertRow('leads', [
            'public_id' => Ulid::generate(), 'lead_number' => 'LEAD-TEST-' . random_int(100000, 999999),
            'branch_id' => $this->branchA, 'name' => 'Existing', 'phone' => '9812349999',
            'priority' => 'medium', 'status_id' => $this->leads->defaultStatusId(), 'created_by' => $actor->id,
        ]);

        $file = $this->file(['Name,Phone', 'Duplicate Guy,9812349999']);
        $batch = $this->service->stage($file, $actor, $this->branchA)['batch'];

        $result = $this->service->confirm($batch, $batch->mapping, false, $actor);
        self::assertSame(0, $result->importedRows);
        self::assertSame(1, $result->skippedRows);

        // Re-stage the same file and import anyway.
        $file2 = $this->file(['Name,Phone', 'Duplicate Guy,9812349999']);
        $batch2 = $this->service->stage($file2, $actor, $this->branchA)['batch'];
        $result2 = $this->service->confirm($batch2, $batch2->mapping, true, $actor);
        self::assertSame(1, $result2->importedRows);
    }

    public function test_source_and_assignee_lookups_resolve_or_are_silently_dropped(): void
    {
        $actor = $this->actor('manager');
        $counselor = $this->actor('counselor');
        $sourceRow = $this->db->selectOne('SELECT id, name FROM lead_sources WHERE is_active = 1 LIMIT 1');
        if ($sourceRow === null) {
            self::markTestSkipped('no lead_sources seeded');
        }

        $file = $this->file([
            'Name,Phone,Source,Assignee',
            'Matched,9812350001,' . $sourceRow['name'] . ',' . $counselor->email,
            'Unmatched,9812350002,Nonexistent Source Xyz,nobody@nowhere.example',
        ]);
        $batch = $this->service->stage($file, $actor, $this->branchA)['batch'];
        $mapping = ['0' => 'name', '1' => 'phone', '2' => 'source', '3' => 'assignee_email'];

        $result = $this->service->confirm($batch, $mapping, false, $actor);
        self::assertSame(2, $result->importedRows);

        $matched = $this->leads->findLikelyDuplicates($this->scopeFor($actor), '9812350001', null, null);
        self::assertNotEmpty($matched);
        $lead = $this->leads->findById((int) $matched[0]['id'], $this->scopeFor($actor));
        self::assertSame((int) $sourceRow['id'], $lead->sourceId);
        self::assertSame($counselor->id, $lead->assignedTo);

        $unmatched = $this->leads->findLikelyDuplicates($this->scopeFor($actor), '9812350002', null, null);
        $lead2 = $this->leads->findById((int) $unmatched[0]['id'], $this->scopeFor($actor));
        self::assertNull($lead2->sourceId);
        self::assertNull($lead2->assignedTo);
    }

    public function test_confirm_requires_name_and_phone_to_be_mapped(): void
    {
        $actor = $this->actor('manager');
        $file = $this->file(['Name,Notes', 'No Phone Here,some notes']);
        $batch = $this->service->stage($file, $actor, $this->branchA)['batch'];

        $this->expectException(ValidationException::class);
        $this->service->confirm($batch, ['0' => 'name', '1' => 'notes'], false, $actor);
    }

    public function test_confirm_twice_is_rejected(): void
    {
        $actor = $this->actor('manager');
        $file = $this->file(['Name,Phone', 'Once,9812360001']);
        $batch = $this->service->stage($file, $actor, $this->branchA)['batch'];

        $done = $this->service->confirm($batch, $batch->mapping, false, $actor);
        self::assertSame('completed', $done->status);

        // A second confirm — as a repeated request would — re-fetches the batch,
        // which is now 'completed', not 'previewed'.
        $refetched = $this->repo->findBatchByPublicId($batch->publicId, $actor->id);
        $this->expectException(\App\Exceptions\DomainRuleException::class);
        $this->service->confirm($refetched, $refetched->mapping, false, $actor);
    }

    public function test_stage_enforces_the_row_cap(): void
    {
        $this->app->get(\App\Support\Config::class)->set('import_export.leads.import.max_rows', 2);
        $actor = $this->actor('manager');
        $file = $this->file(['Name,Phone', 'A,9812370001', 'B,9812370002', 'C,9812370003']);

        $this->expectException(ValidationException::class);
        $this->service->stage($file, $actor, $this->branchA);
    }
}
