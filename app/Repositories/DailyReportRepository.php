<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Support\Db;

/** One-day activity figures for the daily digest. Read-only, branch-scoped, one grouped statement per figure. */
final class DailyReportRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Rows of `$table` whose `$dateColumn` falls on `$date` (a UTC calendar day). */
    public function countOn(string $table, string $dateColumn, string $branchColumn, BranchScope $scope, string $date, string $extraWhere = '1 = 1'): int
    {
        $this->assertIdentifiers($table, $dateColumn, $branchColumn);
        [$branchSql, $bind] = $scope->whereClause($branchColumn);

        return (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM {$table} WHERE DATE({$dateColumn}) = :d AND {$branchSql} AND {$extraWhere}",
            ['d' => $date] + $bind,
        );
    }

    /**
     * Money on the day, per currency: how many rows and their total.
     *
     * @return array<string,array{count:int,total:string}> currency => figures
     */
    public function moneyOn(string $table, string $amountColumn, string $dateColumn, string $branchColumn, BranchScope $scope, string $date, string $extraWhere = '1 = 1'): array
    {
        $this->assertIdentifiers($table, $amountColumn, $dateColumn, $branchColumn);
        [$branchSql, $bind] = $scope->whereClause($branchColumn);
        $rows = $this->db->select(
            "SELECT currency, COUNT(*) AS n, SUM({$amountColumn}) AS total FROM {$table}
             WHERE DATE({$dateColumn}) = :d AND {$branchSql} AND {$extraWhere} GROUP BY currency ORDER BY currency",
            ['d' => $date] + $bind,
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['currency']] = ['count' => (int) $r['n'], 'total' => number_format((float) $r['total'], 2, '.', '')];
        }

        return $out;
    }

    /** Interviews that took place on the day (an outcome was reached or the candidate did not show). */
    public function interviewsHeld(BranchScope $scope, string $date): int
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');

        return (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM interviews i JOIN applications a ON a.id = i.application_id
             WHERE i.scheduled_date = :d AND i.status IN ('completed','selected','rejected','no_show') AND {$branchSql}",
            ['d' => $date] + $bind,
        );
    }

    /** @return list<array{id:int,name:string}> branches to report on */
    public function activeBranches(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $this->db->select('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id'),
        );
    }

    /** Claim a (date, audience) slot. True only for the caller that created it — the "run-date guard". */
    public function claim(string $key, string $json): bool
    {
        return $this->db->affectingStatement('INSERT IGNORE INTO settings (key_name, value, is_public) VALUES (:k, :v, 0)', ['k' => $key, 'v' => $json]) === 1;
    }

    private function assertIdentifiers(string ...$names): void
    {
        foreach ($names as $n) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/i', $n) !== 1) {
                throw new \InvalidArgumentException("Not an identifier: {$n}");
            }
        }
    }
}
