<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Candidate;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/**
 * SQL for the candidate aggregate (`candidates` joined to `persons` and,
 * optionally, the originating lead). Detail/list reads are branch-scoped.
 */
final class CandidateRepository
{
    public const SORT = [
        'created_at' => 'c.created_at',
        'name'       => 'p.full_name',
        'stage'      => 'c.stage',
    ];

    public const FILTER_KEYS = ['stage', 'counselor'];

    private const COLUMNS = "c.id, c.public_id, c.candidate_number, c.person_id, c.branch_id, c.origin_lead_id,
        c.stage, c.marital_status, c.current_country, c.highest_qualification, c.total_experience_years,
        c.assigned_counselor, c.is_active, c.record_version, c.created_at, c.updated_at,
        p.full_name, p.gender, p.date_of_birth, p.primary_phone, p.alternate_phone, p.email,
        p.nationality, p.city, p.state, p.country,
        u.name AS counselor_name, l.lead_number AS origin_lead_number, l.public_id AS origin_lead_public_id";

    private const JOINS = "FROM candidates c
        JOIN persons p ON p.id = c.person_id
        LEFT JOIN users u ON u.id = c.assigned_counselor
        LEFT JOIN leads l ON l.id = c.origin_lead_id";

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Candidate
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE c.id = :id AND c.deleted_at IS NULL AND {$branchSql}",
            ['id' => $id] + $bind,
        );

        return $row ? Candidate::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Candidate
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE c.public_id = :pid AND c.deleted_at IS NULL AND {$branchSql}",
            ['pid' => $publicId] + $bind,
        );

        return $row ? Candidate::fromRow($row) : null;
    }

    public function findIdByPersonId(int $personId): ?int
    {
        $id = $this->db->selectValue(
            'SELECT id FROM candidates WHERE person_id = :pid AND deleted_at IS NULL LIMIT 1',
            ['pid' => $personId],
        );

        return $id !== null ? (int) $id : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('candidates', $data);
    }

    /** @return Page<Candidate> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM candidates c JOIN persons p ON p.id = c.person_id WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'c.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, c.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Candidate::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return list<array{key:string,label:string}> distinct stages in use, for the filter dropdown */
    public function stageOptions(): array
    {
        $rows = $this->db->select("SELECT DISTINCT stage FROM candidates WHERE deleted_at IS NULL ORDER BY stage");

        return array_map(
            static fn (array $r): array => ['key' => (string) $r['stage'], 'label' => ucwords(str_replace('_', ' ', (string) $r['stage']))],
            $rows,
        );
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $where = [$branchSql, 'c.deleted_at IS NULL'];

        if (($stage = $q->filter('stage')) !== null && $stage !== '') {
            $where[] = 'c.stage = :f_stage';
            $bind['f_stage'] = $stage;
        }
        if (($counselor = $q->filter('counselor')) !== null && ctype_digit($counselor)) {
            $where[] = 'c.assigned_counselor = :f_counselor';
            $bind['f_counselor'] = (int) $counselor;
        }

        if ($q->hasSearch()) {
            $term = $q->search;
            $prefix = $this->likePrefix($term);
            $where[] = '(c.candidate_number = :s_exact OR p.primary_phone LIKE :s_phone
                        OR p.email LIKE :s_email OR p.full_name LIKE :s_name)';
            $bind['s_exact'] = $term;
            $bind['s_phone'] = $prefix;
            $bind['s_email'] = $prefix;
            $bind['s_name'] = '%' . $this->escapeLike($term) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function likePrefix(string $value): string
    {
        return $this->escapeLike($value) . '%';
    }
}
