<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScope;
use App\Models\Lead;
use App\Repositories\LeadRepository;
use App\Support\ListQuery;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class LeadRepositoryTest extends DbTestCase
{
    private LeadRepository $repo;
    private int $branchA;
    private int $branchB;
    private int $newStatusId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new LeadRepository($this->db);

        $this->newStatusId = (int) $this->db->selectValue("SELECT id FROM lead_statuses WHERE key_name = 'new'");
        if ($this->newStatusId === 0) {
            self::markTestSkipped('lead reference data not seeded');
        }

        $this->branchA = $this->makeBranch('TEST-A');
        $this->branchB = $this->makeBranch('TEST-B');
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM leads WHERE lead_number LIKE 'T-%'");
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'TEST-%'");
    }

    private function makeBranch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(),
            'name' => "Branch {$code}",
            'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function makeLead(int $branchId, array $overrides = []): int
    {
        static $n = 0;
        $n++;

        return $this->repo->insert(array_merge([
            'public_id' => Ulid::generate(),
            'lead_number' => 'T-' . bin2hex(random_bytes(4)) . '-' . $n,
            'branch_id' => $branchId,
            'name' => 'Lead ' . $n,
            'phone' => '90000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'status_id' => $this->newStatusId,
            'priority' => 'medium',
        ], $overrides));
    }

    public function test_find_by_public_id_respects_branch_scope(): void
    {
        $id = $this->makeLead($this->branchA, ['public_id' => $pid = Ulid::generate()]);

        self::assertInstanceOf(Lead::class, $this->repo->findByPublicId($pid, BranchScope::of([$this->branchA])));
        self::assertNull($this->repo->findByPublicId($pid, BranchScope::of([$this->branchB])));
        self::assertInstanceOf(Lead::class, $this->repo->findByPublicId($pid, BranchScope::orgWide()));
        self::assertSame($id, $this->repo->findByPublicId($pid, BranchScope::orgWide())->id);
    }

    public function test_paginate_filters_by_branch_and_status(): void
    {
        $this->makeLead($this->branchA);
        $this->makeLead($this->branchA, ['status_id' => (int) $this->db->selectValue("SELECT id FROM lead_statuses WHERE key_name='lost'")]);
        $this->makeLead($this->branchB);

        $page = $this->repo->paginate(ListQuery::of(['perPage' => 25]), BranchScope::of([$this->branchA]));
        self::assertSame(2, $page->total);

        $newOnly = $this->repo->paginate(ListQuery::of(['filters' => ['status' => 'new']]), BranchScope::of([$this->branchA]));
        self::assertSame(1, $newOnly->total);
    }

    public function test_search_matches_phone_prefix_and_lead_number_exact(): void
    {
        $this->makeLead($this->branchA, ['phone' => '9112223344', 'lead_number' => 'T-FINDME-1']);
        $this->makeLead($this->branchA, ['phone' => '9998887766']);

        $byPhone = $this->repo->paginate(ListQuery::of(['search' => '91122']), BranchScope::of([$this->branchA]));
        self::assertSame(1, $byPhone->total);

        $byNumber = $this->repo->paginate(ListQuery::of(['search' => 'T-FINDME-1']), BranchScope::of([$this->branchA]));
        self::assertSame(1, $byNumber->total);
    }

    public function test_optimistic_update_rejects_stale_version(): void
    {
        $id = $this->makeLead($this->branchA);
        $scope = BranchScope::of([$this->branchA]);

        self::assertSame(1, $this->repo->update($id, ['name' => 'Renamed'], 1, $scope));
        self::assertSame(0, $this->repo->update($id, ['name' => 'Again'], 1, $scope), 'stale version rejected');
        self::assertSame(1, $this->repo->update($id, ['name' => 'Again'], 2, $scope));

        $lead = $this->repo->findById($id, $scope);
        self::assertSame('Again', $lead->name);
        self::assertSame(3, $lead->recordVersion);
    }

    public function test_soft_delete_hides_from_queries(): void
    {
        $id = $this->makeLead($this->branchA);
        $scope = BranchScope::of([$this->branchA]);

        self::assertSame(1, $this->repo->softDelete($id, 1, $scope));
        self::assertNull($this->repo->findById($id, $scope));
        self::assertInstanceOf(Lead::class, $this->repo->findById($id, $scope, withTrashed: true));
    }

    public function test_find_likely_duplicates(): void
    {
        $this->makeLead($this->branchA, ['phone' => '9555000111', 'email' => 'dup@example.com']);
        $scope = BranchScope::of([$this->branchA]);

        self::assertCount(1, $this->repo->findLikelyDuplicates($scope, '9555000111', null, null));
        self::assertCount(1, $this->repo->findLikelyDuplicates($scope, null, null, 'dup@example.com'));
        self::assertCount(0, $this->repo->findLikelyDuplicates($scope, '9000000000', null, 'nobody@example.com'));
        // not visible from another branch
        self::assertCount(0, $this->repo->findLikelyDuplicates(BranchScope::of([$this->branchB]), '9555000111', null, null));
    }

    public function test_status_counts(): void
    {
        $this->makeLead($this->branchA);
        $this->makeLead($this->branchA);
        $counts = $this->repo->statusCounts(BranchScope::of([$this->branchA]));
        self::assertSame(2, $counts['new'] ?? 0);
    }

    public function test_bulk_assign_skips_converted_and_other_branches(): void
    {
        $a1 = $this->makeLead($this->branchA);
        $a2 = $this->makeLead($this->branchA, ['converted_candidate_id' => 999999]);
        $b1 = $this->makeLead($this->branchB);

        $updated = $this->repo->bulkAssign([$a1, $a2, $b1], null, BranchScope::of([$this->branchA]));
        self::assertSame(1, $updated, 'only the non-converted branch-A lead is updated');
    }
}
