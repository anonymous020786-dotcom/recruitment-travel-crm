<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Sql;
use App\Auth\BranchScope;
use App\Models\MedicalRecord;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `medical_records`, branch-scoped through the candidate's branch. */
final class MedicalRepository
{
    public const SORT = [
        'created_at'  => 'm.created_at',
        'appointment' => 'm.appointment_date',
        'candidate'   => 'p.full_name',
        'expires'     => 'm.expires_at',
        'status'      => 'm.status',
    ];

    public const FILTER_KEYS = ['status', 'expiry'];

    private const OPEN_SQL = "('pending','scheduled','completed')";

    private const COLUMNS = 'm.id, m.public_id, m.candidate_id, m.application_id, m.medical_center, m.appointment_date, m.medical_date,
        m.report_date, m.result, m.expires_at, m.status, m.notes, m.created_at,
        c.public_id AS candidate_public_id, c.candidate_number, c.branch_id, p.full_name AS candidate_name,
        a.public_id AS application_public_id, a.application_number';

    private const JOINS = 'FROM medical_records m
        JOIN candidates c ON c.id = m.candidate_id
        JOIN persons p ON p.id = c.person_id
        LEFT JOIN applications a ON a.id = m.application_id';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?MedicalRecord
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE m.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? MedicalRecord::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?MedicalRecord
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE m.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? MedicalRecord::fromRow($row) : null;
    }

    /** @return list<MedicalRecord> newest first; the caller has already authorized the candidate */
    public function forCandidate(int $candidateId): array
    {
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE m.candidate_id = :c ORDER BY m.id DESC', ['c' => $candidateId]);

        return array_map([MedicalRecord::class, 'fromRow'], $rows);
    }

    /** One exam in progress per candidate + application (NULL application = general medical). */
    public function hasOpenFor(int $candidateId, ?int $applicationId): bool
    {
        return $this->db->exists(
            'SELECT 1 FROM medical_records WHERE candidate_id = :c AND status IN ' . self::OPEN_SQL . ' AND application_id <=> :a',
            ['c' => $candidateId, 'a' => $applicationId],
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('medical_records', $data);
    }

    /**
     * Guarded write: only touches an exam that is still in progress.
     *
     * @param array<string,mixed> $set
     * @return int rows affected (0 => already has a verdict)
     */
    public function updateOpen(int $id, array $set): int
    {
        $cols = [];
        $bind = ['id' => $id];
        foreach ($set as $col => $val) {
            $cols[] = Sql::assign($col, 's_');
            $bind["s_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE medical_records SET ' . implode(', ', $cols) . ' WHERE id = :id AND status IN ' . self::OPEN_SQL,
            $bind,
        );
    }

    /** Only an exam that has not happened yet can be deleted. */
    public function deleteUnstarted(int $id): int
    {
        return $this->db->affectingStatement("DELETE FROM medical_records WHERE id = :id AND status IN ('pending','scheduled')", ['id' => $id]);
    }

    /**
     * Fit certificates at or inside the widest reminder window, for candidates
     * who still have a live application (old certificates of finished candidates are noise).
     *
     * @return list<array<string,mixed>> id, candidate_id, candidate_name, expires_at, branch_id, owner_id
     */
    public function dueForReminder(int $maxDays, string $today): array
    {
        return $this->db->select(
            "SELECT m.id, m.candidate_id, m.expires_at, c.branch_id, p.full_name AS candidate_name,
                    COALESCE(a.assigned_to, c.assigned_counselor) AS owner_id
             FROM medical_records m
             JOIN candidates c ON c.id = m.candidate_id
             JOIN persons p ON p.id = c.person_id
             LEFT JOIN applications a ON a.id = m.application_id
             WHERE m.status = 'fit' AND m.expires_at IS NOT NULL AND m.expires_at <= (:today + INTERVAL :days DAY)
               AND EXISTS (SELECT 1 FROM applications a2 WHERE a2.candidate_id = m.candidate_id AND a2.status NOT IN ('placed','rejected','cancelled'))
             ORDER BY m.expires_at",
            ['today' => $today, 'days' => $maxDays],
        );
    }

    /** @return Page<MedicalRecord> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM medical_records m JOIN candidates c ON c.id = m.candidate_id JOIN persons p ON p.id = c.person_id WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'm.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, m.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([MedicalRecord::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $where = [$branchSql];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'm.status = :f_status';
            $bind['f_status'] = $status;
        }
        $expiry = $q->filter('expiry');
        if ($expiry === 'expired') {
            $where[] = "m.status = 'fit' AND m.expires_at < :f_today";
            $bind['f_today'] = gmdate('Y-m-d');
        } elseif ($expiry === 'expiring') {
            $where[] = "m.status = 'fit' AND m.expires_at BETWEEN :f_today AND :f_until";
            $bind['f_today'] = gmdate('Y-m-d');
            $bind['f_until'] = gmdate('Y-m-d', strtotime('+' . MedicalRecord::EXPIRING_DAYS . ' days'));
        }
        if ($q->hasSearch()) {
            $where[] = '(c.candidate_number = :s_exact OR p.full_name LIKE :s_name OR m.medical_center LIKE :s_centre)';
            $bind['s_exact'] = $q->search;
            $bind['s_name'] = $bind['s_centre'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
