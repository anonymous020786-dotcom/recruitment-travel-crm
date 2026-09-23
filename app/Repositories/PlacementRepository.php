<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Placement;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `placements`, branch-scoped on `placements.branch_id`. */
final class PlacementRepository
{
    public const SORT = [
        'placed_on' => 'pl.placed_on',
        'candidate' => 'p.full_name',
        'employer'  => 'e.company_name',
        'status'    => 'pl.status',
    ];

    public const FILTER_KEYS = ['status', 'employer'];

    private const COLUMNS = 'pl.id, pl.public_id, pl.candidate_id, pl.application_id, pl.employer_id, pl.job_id, pl.branch_id,
        pl.placed_on, pl.monthly_salary, pl.currency, pl.contract_end, pl.status, pl.created_at,
        c.public_id AS candidate_public_id, c.candidate_number, p.full_name AS candidate_name,
        a.public_id AS application_public_id, a.application_number,
        e.public_id AS employer_public_id, e.company_name AS employer_name, j.title AS job_title';

    private const JOINS = 'FROM placements pl
        JOIN candidates c ON c.id = pl.candidate_id
        JOIN persons p ON p.id = c.person_id
        JOIN applications a ON a.id = pl.application_id
        JOIN employers e ON e.id = pl.employer_id
        JOIN jobs j ON j.id = pl.job_id';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Placement
    {
        [$branchSql, $bind] = $scope->whereClause('pl.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE pl.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? Placement::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Placement
    {
        [$branchSql, $bind] = $scope->whereClause('pl.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE pl.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? Placement::fromRow($row) : null;
    }

    /** The application's placement, if it has one. The caller has already authorized the application. */
    public function forApplication(int $applicationId): ?Placement
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE pl.application_id = :a', ['a' => $applicationId]);

        return $row ? Placement::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('placements', $data);
    }

    /** Guarded: only an active placement can end. @return int rows affected */
    public function end(int $id, string $to): int
    {
        return $this->db->affectingStatement("UPDATE placements SET status = :to WHERE id = :id AND status = 'active'", ['to' => $to, 'id' => $id]);
    }

    /** @return Page<Placement> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM placements pl JOIN candidates c ON c.id = pl.candidate_id JOIN persons p ON p.id = c.person_id
             JOIN employers e ON e.id = pl.employer_id WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'pl.placed_on') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, pl.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Placement::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('pl.branch_id');
        $where = [$branchSql];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'pl.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($employer = $q->filter('employer')) !== null && $employer !== '') {
            $where[] = 'e.public_id = :f_employer';
            $bind['f_employer'] = $employer;
        }
        if ($q->hasSearch()) {
            $where[] = '(c.candidate_number = :s_exact OR p.full_name LIKE :s_name OR e.company_name LIKE :s_company)';
            $bind['s_exact'] = $q->search;
            $bind['s_name'] = $bind['s_company'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
