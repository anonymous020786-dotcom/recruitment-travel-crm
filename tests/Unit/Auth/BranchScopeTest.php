<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\BranchScope;
use PHPUnit\Framework\TestCase;

final class BranchScopeTest extends TestCase
{
    public function test_org_wide_contains_everything(): void
    {
        $scope = BranchScope::orgWide();
        self::assertTrue($scope->contains(1));
        self::assertTrue($scope->contains(999));
        self::assertTrue($scope->contains(null));
        self::assertFalse($scope->isEmpty());
    }

    public function test_scoped_contains_only_listed(): void
    {
        $scope = BranchScope::of([2, 5, 5, 9]);
        self::assertTrue($scope->contains(2));
        self::assertTrue($scope->contains(9));
        self::assertFalse($scope->contains(3));
        self::assertFalse($scope->contains(null));
        self::assertSame([2, 5, 9], $scope->ids);
    }

    public function test_empty_scope(): void
    {
        $scope = BranchScope::of([]);
        self::assertTrue($scope->isEmpty());
        self::assertFalse($scope->contains(1));
    }

    public function test_where_clause_org_wide(): void
    {
        self::assertSame(['1 = 1', []], BranchScope::orgWide()->whereClause());
    }

    public function test_where_clause_empty(): void
    {
        self::assertSame(['1 = 0', []], BranchScope::of([])->whereClause());
    }

    public function test_where_clause_scoped(): void
    {
        [$sql, $params] = BranchScope::of([3, 7])->whereClause('b.branch_id');
        self::assertSame('b.branch_id IN (:bs0, :bs1)', $sql);
        self::assertSame(['bs0' => 3, 'bs1' => 7], $params);
    }
}
