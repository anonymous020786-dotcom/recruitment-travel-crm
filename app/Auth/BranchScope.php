<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The set of branches an acting user may see. Either organisation-wide (all
 * branches) or a concrete list of branch ids. Repositories add a
 * `branch_id IN (...)` predicate from this; policies call `contains()`.
 */
final class BranchScope
{
    /** @param list<int> $ids */
    private function __construct(
        public readonly bool $orgWide,
        public readonly array $ids,
    ) {
    }

    public static function orgWide(): self
    {
        return new self(true, []);
    }

    /** @param list<int> $ids */
    public static function of(array $ids): self
    {
        return new self(false, array_values(array_unique(array_map('intval', $ids))));
    }

    public function contains(?int $branchId): bool
    {
        if ($this->orgWide) {
            return true;
        }
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, $this->ids, true);
    }

    public function isEmpty(): bool
    {
        return !$this->orgWide && $this->ids === [];
    }

    /**
     * SQL fragment + bindings for a WHERE clause. Org-wide → always-true.
     *
     * @return array{0:string,1:array<string,int>}
     */
    public function whereClause(string $column = 'branch_id'): array
    {
        if ($this->orgWide) {
            return ['1 = 1', []];
        }
        if ($this->ids === []) {
            return ['1 = 0', []];
        }

        $params = [];
        $names = [];
        foreach ($this->ids as $i => $id) {
            $names[] = ":bs{$i}";
            $params["bs{$i}"] = $id;
        }

        return [$column . ' IN (' . implode(', ', $names) . ')', $params];
    }
}
