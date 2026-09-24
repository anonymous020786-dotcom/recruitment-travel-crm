<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Sql;
use App\Auth\BranchScope;
use App\Models\Application;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `applications`, branch-scoped on `applications.branch_id`, optimistic-locked on `record_version`. */
final class ApplicationRepository
{
    public const SORT = [
        'applied_at' => 'a.applied_at',
        'candidate'  => 'p.full_name',
        'status'     => 'a.status',
        'score'      => 'a.match_score',
    ];

    public const FILTER_KEYS = ['status', 'job', 'employer'];

    private const COLUMNS = 'a.id, a.public_id, a.application_number, a.candidate_id, a.job_id, a.employer_id, a.branch_id,
        a.status, a.match_score, a.match_breakdown, a.assigned_to, a.applied_at, a.closed_at, a.cancel_reason,
        a.record_version, a.created_at,
        c.public_id AS candidate_public_id, c.candidate_number, p.full_name AS candidate_name,
        j.public_id AS job_public_id, j.job_number, j.title AS job_title,
        e.public_id AS employer_public_id, e.company_name AS employer_name, u.name AS assigned_to_name';

    private const JOINS = 'FROM applications a
        JOIN candidates c ON c.id = a.candidate_id
        JOIN persons p ON p.id = c.person_id
        JOIN jobs j ON j.id = a.job_id
        JOIN employers e ON e.id = a.employer_id
        LEFT JOIN users u ON u.id = a.assigned_to';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Application
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE a.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? Application::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Application
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE a.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? Application::fromRow($row) : null;
    }

    public function findByNumber(string $applicationNumber, BranchScope $scope): ?Application
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE a.application_number = :n AND {$branchSql}", ['n' => $applicationNumber] + $bind);

        return $row ? Application::fromRow($row) : null;
    }

    public function existsFor(int $candidateId, int $jobId): bool
    {
        return $this->db->exists('SELECT 1 FROM applications WHERE candidate_id = :c AND job_id = :j', ['c' => $candidateId, 'j' => $jobId]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('applications', $data);
    }

    /**
     * Optimistic status write.
     *
     * @param array<string,mixed> $extra additional columns (closed_at, cancel_reason…)
     * @return int rows affected (0 => stale, or outside scope)
     */
    public function updateStatus(int $id, string $to, array $extra, int $expectedVersion, BranchScope $scope): int
    {
        [$branchSql, $branchBind] = $scope->whereClause('branch_id');

        $set = ['status = :to', 'record_version = record_version + 1', 'updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id, 'ver' => $expectedVersion, 'to' => $to] + $branchBind;
        foreach ($extra as $col => $val) {
            $set[] = Sql::assign($col, 'c_');
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE applications SET ' . implode(', ', $set) . " WHERE id = :id AND record_version = :ver AND {$branchSql}",
            $bind,
        );
    }

    /** @return list<string> every application status held by a candidate (for the stage snapshot) */
    public function statusesForCandidate(int $candidateId): array
    {
        return array_map('strval', array_column(
            $this->db->select('SELECT status FROM applications WHERE candidate_id = :c', ['c' => $candidateId]),
            'status',
        ));
    }

    /** @return list<Application> */
    public function forCandidate(int $candidateId, BranchScope $scope, int $limit = 50): array
    {
        return $this->listWhere('a.candidate_id = :cid', ['cid' => $candidateId], $scope, $limit);
    }

    /** @return list<Application> */
    public function forJob(int $jobId, BranchScope $scope, int $limit = 100): array
    {
        return $this->listWhere('a.job_id = :jid', ['jid' => $jobId], $scope, $limit);
    }

    /** @return Page<Application> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        // The candidate/person joins only matter to the count when the search reads p.full_name; both are
        // guaranteed by foreign keys, so leaving them out never changes the number.
        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM applications a'
            . (Sql::references($where, 'p') ? ' JOIN candidates c ON c.id = a.candidate_id JOIN persons p ON p.id = c.person_id' : '')
            . ' WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'a.applied_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        // STRAIGHT_JOIN: walk applications in ORDER BY order (idx_applications_branch_applied) and join the five
        // lookup tables for just the page's rows, instead of the optimizer driving from `jobs` and sorting
        // every matching row (≈190 ms → ≈3 ms at 100k applications). Safe because a LIMIT is present.
        $rows = $this->db->select(
            'SELECT STRAIGHT_JOIN ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, a.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Application::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /**
     * @param array<string,mixed> $extraBind
     * @return list<Application>
     */
    private function listWhere(string $condition, array $extraBind, BranchScope $scope, int $limit): array
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $limit = max(1, min($limit, 200));
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$condition} AND {$branchSql} ORDER BY a.id DESC LIMIT {$limit}",
            $extraBind + $bind,
        );

        return array_map([Application::class, 'fromRow'], $rows);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $where = [$branchSql];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'a.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($job = $q->filter('job')) !== null && $job !== '') {
            $where[] = 'a.job_id = (SELECT id FROM jobs WHERE public_id = :f_job)';
            $bind['f_job'] = $job;
        }
        if (($employer = $q->filter('employer')) !== null && $employer !== '') {
            $where[] = 'a.employer_id = (SELECT id FROM employers WHERE public_id = :f_employer)';
            $bind['f_employer'] = $employer;
        }
        if ($q->hasSearch()) {
            $where[] = '(a.application_number = :s_exact OR p.full_name LIKE :s_name)';
            $bind['s_exact'] = $q->search;
            $bind['s_name'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
