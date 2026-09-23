<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Interview;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `interviews`, branch-scoped through the owning application's branch. */
final class InterviewRepository
{
    public const SORT = [
        'when'      => 'i.scheduled_date',
        'candidate' => 'p.full_name',
        'status'    => 'i.status',
    ];

    public const FILTER_KEYS = ['status', 'type', 'when'];

    private const COLUMNS = 'i.id, i.public_id, i.application_id, i.candidate_id, i.job_id, i.employer_id, i.round_no, i.type,
        i.scheduled_date, i.scheduled_time, i.location, i.meeting_link, i.interviewer, i.status, i.result, i.feedback, i.notes, i.created_at,
        a.public_id AS application_public_id, a.application_number, a.branch_id, a.assigned_to,
        c.public_id AS candidate_public_id, p.full_name AS candidate_name,
        j.public_id AS job_public_id, j.title AS job_title, e.company_name AS employer_name';

    private const JOINS = 'FROM interviews i
        JOIN applications a ON a.id = i.application_id
        JOIN candidates c ON c.id = i.candidate_id
        JOIN persons p ON p.id = c.person_id
        JOIN jobs j ON j.id = i.job_id
        JOIN employers e ON e.id = i.employer_id';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope): ?Interview
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE i.id = :id AND {$branchSql}", ['id' => $id] + $bind);

        return $row ? Interview::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?Interview
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE i.public_id = :pid AND {$branchSql}", ['pid' => $publicId] + $bind);

        return $row ? Interview::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('interviews', $data);
    }

    /** @return list<Interview> oldest round first */
    public function forApplication(int $applicationId, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE i.application_id = :aid AND {$branchSql} ORDER BY i.id",
            ['aid' => $applicationId] + $bind,
        );

        return array_map([Interview::class, 'fromRow'], $rows);
    }

    /** The newest interview of an application, for round bookkeeping. @return array{round_no:int,status:string}|null */
    public function latestFor(int $applicationId): ?array
    {
        $row = $this->db->selectOne('SELECT round_no, status FROM interviews WHERE application_id = :a ORDER BY id DESC LIMIT 1', ['a' => $applicationId]);

        return $row ? ['round_no' => (int) $row['round_no'], 'status' => (string) $row['status']] : null;
    }

    public function hasOpen(int $applicationId): bool
    {
        return $this->db->exists("SELECT 1 FROM interviews WHERE application_id = :a AND status IN ('scheduled','confirmed')", ['a' => $applicationId]);
    }

    /**
     * Guarded write: only touches a still-open interview, so two people acting
     * on the same interview cannot both win.
     *
     * @param array<string,mixed> $set
     * @return int rows affected (0 => no longer open)
     */
    public function updateOpen(int $id, array $set): int
    {
        $cols = [];
        $bind = ['id' => $id];
        foreach ($set as $col => $val) {
            $cols[] = "`{$col}` = :s_{$col}";
            $bind["s_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE interviews SET ' . implode(', ', $cols) . " WHERE id = :id AND status IN ('scheduled','confirmed')",
            $bind,
        );
    }

    /** @return Page<Interview> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM interviews i JOIN applications a ON a.id = i.application_id
             JOIN candidates c ON c.id = i.candidate_id JOIN persons p ON p.id = c.person_id WHERE ' . $where,
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'i.scheduled_date') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, i.scheduled_time, i.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Interview::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /**
     * Open interviews on a given date, for the reminder cron.
     *
     * @return list<array<string,mixed>>
     */
    public function openOn(string $date): array
    {
        return $this->db->select(
            "SELECT i.id, i.round_no, i.type, i.scheduled_date, i.scheduled_time, a.id AS application_id, a.assigned_to,
                    p.full_name AS candidate_name, j.title AS job_title
             FROM interviews i JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = i.candidate_id
             JOIN persons p ON p.id = c.person_id JOIN jobs j ON j.id = i.job_id
             WHERE i.scheduled_date = :d AND i.status IN ('scheduled','confirmed') AND a.status = 'interview_scheduled' AND a.assigned_to IS NOT NULL",
            ['d' => $date],
        );
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('a.branch_id');
        $where = [$branchSql];
        $today = gmdate('Y-m-d');

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'i.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($type = $q->filter('type')) !== null && $type !== '') {
            $where[] = 'i.type = :f_type';
            $bind['f_type'] = $type;
        }
        $when = $q->filter('when');
        if ($when === 'today') {
            $where[] = 'i.scheduled_date = :f_today';
            $bind['f_today'] = $today;
        } elseif ($when === 'upcoming') {
            $where[] = "i.scheduled_date >= :f_today AND i.status IN ('scheduled','confirmed')";
            $bind['f_today'] = $today;
        } elseif ($when === 'past') {
            $where[] = 'i.scheduled_date < :f_today';
            $bind['f_today'] = $today;
        }
        if ($q->hasSearch()) {
            $where[] = '(a.application_number = :s_exact OR p.full_name LIKE :s_name)';
            $bind['s_exact'] = $q->search;
            $bind['s_name'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
