<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Followup;
use App\Support\Db;

/**
 * SQL for the lead follow-up. Detail / list reads are branch-scoped on
 * `lead_followups.branch_id`; the cron reminder read is system-wide by design.
 */
final class LeadFollowupRepository
{
    private const COLUMNS = "f.id, f.lead_id, f.assigned_to, f.branch_id, f.due_date, f.due_time, f.channel,
        f.subject, f.status, f.outcome, f.completed_at, f.created_by, f.created_at,
        u.name AS assignee_name,
        l.name AS lead_name, l.lead_number, l.public_id AS lead_public_id, l.phone AS lead_phone";

    private const JOINS = "FROM lead_followups f
        JOIN leads l ON l.id = f.lead_id
        LEFT JOIN users u ON u.id = f.assigned_to";

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('lead_followups', $data);
    }

    public function findInScope(int $id, BranchScope $scope): ?Followup
    {
        [$branchSql, $bind] = $scope->whereClause('f.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE f.id = :id AND {$branchSql}",
            ['id' => $id] + $bind,
        );

        return $row ? Followup::fromRow($row) : null;
    }

    /** @return list<Followup> newest due first */
    public function forLead(int $leadId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS
            . ' WHERE f.lead_id = :id ORDER BY f.status = \'pending\' DESC, f.due_date DESC, f.id DESC',
            ['id' => $leadId],
        );

        return array_map([Followup::class, 'fromRow'], $rows);
    }

    /**
     * Pending follow-ups for one user, filtered to a time bucket.
     *
     * @param 'overdue'|'today'|'upcoming' $bucket
     * @return list<Followup>
     */
    public function pendingForUser(int $userId, BranchScope $scope, string $bucket, int $limit = 100): array
    {
        [$branchSql, $bind] = $scope->whereClause('f.branch_id');
        $bind['uid'] = $userId;
        $limit = max(1, min($limit, 500));

        $window = match ($bucket) {
            'overdue'  => 'f.due_date < UTC_DATE()',
            'today'    => 'f.due_date = UTC_DATE()',
            default    => 'f.due_date > UTC_DATE() AND f.due_date <= (UTC_DATE() + INTERVAL 7 DAY)',
        };

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS
            . " WHERE f.assigned_to = :uid AND f.status = 'pending' AND {$branchSql} AND {$window}"
            . ' ORDER BY f.due_date ASC, f.due_time IS NULL, f.due_time ASC, f.id ASC'
            . " LIMIT {$limit}",
            $bind,
        );

        return array_map([Followup::class, 'fromRow'], $rows);
    }

    /** @return array{overdue:int,today:int,upcoming:int} */
    public function countsForUser(int $userId, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('f.branch_id');
        $bind['uid'] = $userId;

        $row = $this->db->selectOne(
            "SELECT
                SUM(f.due_date <  UTC_DATE()) AS overdue,
                SUM(f.due_date =  UTC_DATE()) AS today,
                SUM(f.due_date >  UTC_DATE() AND f.due_date <= (UTC_DATE() + INTERVAL 7 DAY)) AS upcoming
             FROM lead_followups f
             WHERE f.assigned_to = :uid AND f.status = 'pending' AND {$branchSql}",
            $bind,
        );

        return [
            'overdue'  => (int) ($row['overdue'] ?? 0),
            'today'    => (int) ($row['today'] ?? 0),
            'upcoming' => (int) ($row['upcoming'] ?? 0),
        ];
    }

    public function markCompleted(int $id, string $outcome): int
    {
        return $this->db->affectingStatement(
            "UPDATE lead_followups SET status = 'completed', outcome = :o, completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'pending'",
            ['id' => $id, 'o' => mb_substr($outcome, 0, 255)],
        );
    }

    public function markCancelled(int $id): int
    {
        return $this->db->affectingStatement(
            "UPDATE lead_followups SET status = 'cancelled' WHERE id = :id AND status = 'pending'",
            ['id' => $id],
        );
    }

    public function hasOpenForLead(int $leadId): bool
    {
        return $this->db->exists(
            "SELECT 1 FROM lead_followups WHERE lead_id = :id AND status = 'pending'",
            ['id' => $leadId],
        );
    }

    /**
     * All pending follow-ups due on or before the given date — cron reminder
     * feed. System-wide (no branch scope): the cron runs as the system.
     *
     * @return list<array<string,mixed>> raw rows incl. lead_id, lead_name,
     *         lead_number, lead_public_id, due_date, due_time, channel, subject,
     *         assigned_to, assignee_name, assignee_email
     */
    public function dueForReminder(string $onOrBeforeDate, int $limit = 2000): array
    {
        $limit = max(1, min($limit, 5000));

        return $this->db->select(
            'SELECT ' . self::COLUMNS . ', u.email AS assignee_email ' . self::JOINS
            . " WHERE f.status = 'pending' AND f.due_date <= :d AND l.deleted_at IS NULL"
            . ' AND u.is_active = 1 AND u.deleted_at IS NULL'
            . " ORDER BY f.assigned_to, f.due_date LIMIT {$limit}",
            ['d' => $onOrBeforeDate],
        );
    }
}
