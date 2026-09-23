<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Job;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/**
 * SQL for `jobs` (joined to their employer). Reads are branch-scoped on
 * `jobs.branch_id` and exclude soft-deleted rows. Jobs carry no record_version
 * (not in the T20 optimistic-locking list); the status machine, applied through
 * a from-status-guarded UPDATE, is what protects concurrent lifecycle moves.
 */
final class JobRepository
{
    public const SORT = [
        'created_at' => 'j.created_at',
        'title'      => 'j.title',
        'country'    => 'j.country',
        'status'     => 'j.status',
        'deadline'   => 'j.deadline',
    ];

    public const FILTER_KEYS = ['status', 'country', 'employer'];

    private const COLUMNS = 'j.id, j.public_id, j.job_number, j.slug, j.title, j.employer_id, j.branch_id, j.country, j.city,
        j.vacancies, j.salary_min, j.salary_max, j.currency, j.experience_required, j.qualification, j.age_min, j.age_max,
        j.gender_requirement, j.accommodation, j.food, j.transport, j.working_hours, j.overtime, j.contract_duration_months,
        j.interview_type, j.deadline, j.status, j.is_public, j.description_html, j.created_at, j.updated_at,
        e.company_name AS employer_name, e.public_id AS employer_public_id';

    private const JOINS = 'FROM jobs j JOIN employers e ON e.id = j.employer_id';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Job
    {
        [$branchSql, $bind] = $scope->whereClause('j.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE j.id = :id AND j.deleted_at IS NULL AND {$branchSql}",
            ['id' => $id] + $bind,
        );

        return $row ? Job::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Job
    {
        [$branchSql, $bind] = $scope->whereClause('j.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE j.public_id = :pid AND j.deleted_at IS NULL AND {$branchSql}",
            ['pid' => $publicId] + $bind,
        );

        return $row ? Job::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('jobs', $data);
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
            'UPDATE jobs SET ' . implode(', ', $set) . " WHERE id = :id AND deleted_at IS NULL AND {$branchSql}",
            $bind,
        );
    }

    /**
     * Status move guarded on the status the caller last saw, so two people
     * moving the same job concurrently get one winner (0 rows for the loser).
     *
     * @param array<string,mixed> $extra
     */
    public function transition(int $id, string $from, string $to, array $extra, BranchScope $scope): int
    {
        [$branchSql, $branchBind] = $scope->whereClause('branch_id');

        $set = ['status = :to', 'updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id, 'from' => $from, 'to' => $to] + $branchBind;
        foreach ($extra as $col => $val) {
            $set[] = "`{$col}` = :c_{$col}";
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE jobs SET ' . implode(', ', $set) . " WHERE id = :id AND status = :from AND deleted_at IS NULL AND {$branchSql}",
            $bind,
        );
    }

    public function softDelete(int $id, BranchScope $scope): int
    {
        [$branchSql, $bind] = $scope->whereClause('branch_id');

        return $this->db->affectingStatement(
            "UPDATE jobs SET deleted_at = UTC_TIMESTAMP(), is_public = 0 WHERE id = :id AND deleted_at IS NULL AND {$branchSql}",
            ['id' => $id] + $bind,
        );
    }

    /**
     * Open, not-yet-past-deadline jobs in scope (the pool a candidate is matched against).
     *
     * @return list<Job>
     */
    public function openJobs(BranchScope $scope, int $limit = 200): array
    {
        [$branchSql, $bind] = $scope->whereClause('j.branch_id');
        $limit = max(1, min($limit, 500));
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS
            . " WHERE j.status = 'open' AND j.deleted_at IS NULL AND (j.deadline IS NULL OR j.deadline >= UTC_DATE()) AND {$branchSql}"
            . " ORDER BY j.id DESC LIMIT {$limit}",
            $bind,
        );

        return array_map([Job::class, 'fromRow'], $rows);
    }

    /** @return list<Job> */
    public function forEmployer(int $employerId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE j.employer_id = :eid AND j.deleted_at IS NULL ORDER BY j.id DESC LIMIT {$limit}",
            ['eid' => $employerId],
        );

        return array_map([Job::class, 'fromRow'], $rows);
    }

    /** @return Page<Job> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue('SELECT COUNT(*) FROM jobs j WHERE ' . $where, $bind);

        $order = (self::SORT[$q->sort] ?? 'j.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, j.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Job::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('j.branch_id');
        $where = [$branchSql, 'j.deleted_at IS NULL'];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'j.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($country = $q->filter('country')) !== null && $country !== '') {
            $where[] = 'j.country = :f_country';
            $bind['f_country'] = strtoupper($country);
        }
        if (($employer = $q->filter('employer')) !== null && $employer !== '') {
            $where[] = 'j.employer_id = (SELECT id FROM employers WHERE public_id = :f_employer)';
            $bind['f_employer'] = $employer;
        }
        if ($q->hasSearch()) {
            $where[] = '(j.job_number = :s_exact OR j.title LIKE :s_title)';
            $bind['s_exact'] = $q->search;
            $bind['s_title'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
