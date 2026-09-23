<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Employer;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/**
 * SQL for `employers`. Reads are branch-scoped and exclude soft-deleted rows.
 * Employers carry no record_version (docs/00-ARCHITECTURE.md T20 lists only
 * money/application/document/candidate aggregates for optimistic locking).
 */
final class EmployerRepository
{
    public const SORT = [
        'created_at' => 'e.created_at',
        'name'       => 'e.company_name',
        'country'    => 'e.country',
        'status'     => 'e.status',
    ];

    public const FILTER_KEYS = ['status', 'country'];

    private const COLUMNS = 'e.id, e.public_id, e.employer_number, e.company_name, e.country, e.city, e.address,
        e.industry, e.website, e.license_number, e.license_expiry, e.status, e.branch_id, e.account_owner,
        e.notes, e.created_at, e.updated_at, u.name AS account_owner_name';

    private const JOINS = 'FROM employers e LEFT JOIN users u ON u.id = e.account_owner';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Employer
    {
        [$branchSql, $bind] = $scope->whereClause('e.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE e.id = :id AND e.deleted_at IS NULL AND {$branchSql}",
            ['id' => $id] + $bind,
        );

        return $row ? Employer::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Employer
    {
        [$branchSql, $bind] = $scope->whereClause('e.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE e.public_id = :pid AND e.deleted_at IS NULL AND {$branchSql}",
            ['pid' => $publicId] + $bind,
        );

        return $row ? Employer::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('employers', $data);
    }

    /**
     * @param array<string,mixed> $changes
     * @return int rows affected (0 => not found within scope)
     */
    public function update(int $id, array $changes, BranchScope $scope): int
    {
        if ($changes === []) {
            return 0;
        }
        [$branchSql, $branchBind] = $scope->whereClause('branch_id');

        $set = ['updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id] + $branchBind;
        foreach ($changes as $col => $val) {
            $set[] = "`{$col}` = :c_{$col}";
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE employers SET ' . implode(', ', $set) . " WHERE id = :id AND deleted_at IS NULL AND {$branchSql}",
            $bind,
        );
    }

    public function softDelete(int $id, BranchScope $scope): int
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');

        return $this->db->affectingStatement(
            "UPDATE employers SET deleted_at = UTC_TIMESTAMP() WHERE id = :id AND deleted_at IS NULL AND {$branchSql}",
            ['id' => $id] + $bind,
        );
    }

    /** @return Page<Employer> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue('SELECT COUNT(*) FROM employers e WHERE ' . $where, $bind);

        $order = (self::SORT[$q->sort] ?? 'e.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, e.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Employer::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('e.branch_id');
        $where = [$branchSql, 'e.deleted_at IS NULL'];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'e.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($country = $q->filter('country')) !== null && $country !== '') {
            $where[] = 'e.country = :f_country';
            $bind['f_country'] = strtoupper($country);
        }
        if ($q->hasSearch()) {
            // Native prepares: each named placeholder may appear only once.
            $where[] = '(e.employer_number = :s_exact OR e.company_name LIKE :s_name OR e.industry LIKE :s_industry)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
            $bind['s_exact'] = $q->search;
            $bind['s_name'] = $like;
            $bind['s_industry'] = $like;
        }

        return [implode(' AND ', $where), $bind];
    }
}
