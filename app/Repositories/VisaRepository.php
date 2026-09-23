<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\VisaApplication;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `visa_applications`, branch-scoped through the candidate, optimistic-locked on `record_version`. */
final class VisaRepository
{
    public const SORT = [
        'created_at' => 'v.created_at',
        'candidate'  => 'p.full_name',
        'status'     => 'v.status',
        'expiry'     => 'v.expiry_date',
        'country'    => 'v.country',
    ];

    public const FILTER_KEYS = ['status', 'country', 'expiry'];

    private const COLUMNS = 'v.id, v.public_id, v.candidate_id, v.application_id, v.country, v.visa_type, v.visa_number, v.reference_number,
        v.sponsor, v.submission_date, v.approval_date, v.expiry_date, v.status, v.notes, v.record_version, v.created_at,
        c.public_id AS candidate_public_id, c.candidate_number, c.branch_id, c.assigned_counselor AS candidate_counselor, p.full_name AS candidate_name,
        a.public_id AS application_public_id, a.application_number, a.assigned_to, j.title AS job_title';

    private const JOINS = 'FROM visa_applications v
        JOIN candidates c ON c.id = v.candidate_id
        JOIN persons p ON p.id = c.person_id
        LEFT JOIN applications a ON a.id = v.application_id
        LEFT JOIN jobs j ON j.id = a.job_id';

    private const LIVE_SQL = "('not_started','documents_pending','submitted','under_processing','approved')";

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?VisaApplication
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE v.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? VisaApplication::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?VisaApplication
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE v.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? VisaApplication::fromRow($row) : null;
    }

    /** @return list<VisaApplication> newest first; the caller has already authorized the candidate */
    public function forCandidate(int $candidateId): array
    {
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE v.candidate_id = :c ORDER BY v.id DESC', ['c' => $candidateId]);

        return array_map([VisaApplication::class, 'fromRow'], $rows);
    }

    /** One live visa per candidate + application (NULL application = stand-alone visa). */
    public function hasLiveFor(int $candidateId, ?int $applicationId): bool
    {
        return $this->db->exists(
            'SELECT 1 FROM visa_applications WHERE candidate_id = :c AND status IN ' . self::LIVE_SQL . ' AND application_id <=> :a',
            ['c' => $candidateId, 'a' => $applicationId],
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('visa_applications', $data);
    }

    /**
     * Optimistic write of any columns (details or status).
     *
     * @param array<string,mixed> $set
     * @return int rows affected (0 => stale, or outside scope)
     */
    public function update(int $id, array $set, int $expectedVersion, BranchScope $scope): int
    {
        [$branchSql, $branchBind] = $scope->whereClause('branch_id');
        $cols = ['record_version = record_version + 1', 'updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id, 'ver' => $expectedVersion] + $branchBind;
        foreach ($set as $col => $val) {
            $cols[] = "`{$col}` = :s_{$col}";
            $bind["s_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE visa_applications SET ' . implode(', ', $cols)
            . " WHERE id = :id AND record_version = :ver AND candidate_id IN (SELECT id FROM candidates WHERE {$branchSql})",
            $bind,
        );
    }

    public function deleteNotStarted(int $id): int
    {
        return $this->db->affectingStatement("DELETE FROM visa_applications WHERE id = :id AND status = 'not_started'", ['id' => $id]);
    }

    /** @return Page<VisaApplication> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM visa_applications v JOIN candidates c ON c.id = v.candidate_id JOIN persons p ON p.id = c.person_id WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'v.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, v.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([VisaApplication::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $where = [$branchSql];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'v.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($country = $q->filter('country')) !== null && $country !== '') {
            $where[] = 'v.country = :f_country';
            $bind['f_country'] = strtoupper($country);
        }
        $expiry = $q->filter('expiry');
        if ($expiry === 'expired') {
            $where[] = "v.status = 'approved' AND v.expiry_date < :f_today";
            $bind['f_today'] = gmdate('Y-m-d');
        } elseif ($expiry === 'expiring') {
            $where[] = "v.status = 'approved' AND v.expiry_date BETWEEN :f_today AND :f_until";
            $bind['f_today'] = gmdate('Y-m-d');
            $bind['f_until'] = gmdate('Y-m-d', strtotime('+' . VisaApplication::EXPIRING_DAYS . ' days'));
        }
        if ($q->hasSearch()) {
            $where[] = '(c.candidate_number = :s_cand OR v.visa_number = :s_visa OR v.reference_number = :s_ref OR p.full_name LIKE :s_name)';
            $bind['s_cand'] = $bind['s_visa'] = $bind['s_ref'] = $q->search;
            $bind['s_name'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
