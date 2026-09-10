<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\Lead;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/**
 * All SQL for the lead aggregate. Every list/detail query is filtered by the
 * caller's BranchScope; sort keys come from an allowlist; every value is bound.
 */
final class LeadRepository
{
    /** public sort key => ORDER BY expression */
    public const SORT = [
        'created_at'  => 'l.created_at',
        'updated_at'  => 'l.updated_at',
        'name'        => 'l.name',
        'lead_number' => 'l.lead_number',
        'status'      => 'st.sort_order',
        'priority'    => "FIELD(l.priority,'urgent','high','medium','low')",
        'country'     => 'l.interested_country',
    ];

    public const FILTER_KEYS = ['status', 'source', 'assignee', 'priority', 'country', 'from', 'to', 'unassigned'];

    private const LIST_COLUMNS = "l.id, l.public_id, l.lead_number, l.branch_id, l.person_id, l.name, l.phone,
        l.alternate_phone, l.email, l.priority, l.status_id, l.assigned_to, l.interested_country, l.interested_job,
        l.campaign, l.created_at, l.updated_at, l.converted_candidate_id, l.record_version,
        st.key_name AS status_key, st.label AS status_label, st.is_terminal AS status_is_terminal, st.is_won AS status_is_won,
        u.name AS assigned_to_name, src.name AS source_name";

    private const DETAIL_COLUMNS = self::LIST_COLUMNS . ", l.gender, l.date_of_birth, l.city, l.state, l.source_id,
        l.experience_years, l.qualification, l.salary_expectation, l.salary_currency, l.converted_at,
        l.lost_reason, l.notes, l.created_by";

    private const JOINS = "FROM leads l
        JOIN lead_statuses st ON st.id = l.status_id
        LEFT JOIN lead_sources src ON src.id = l.source_id
        LEFT JOIN users u ON u.id = l.assigned_to";

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id, BranchScope $scope, bool $withTrashed = false): ?Lead
    {
        [$branchSql, $branchBind] = $scope->whereClause('l.branch_id');
        $row = $this->db->selectOne(
            "SELECT " . self::DETAIL_COLUMNS . " " . self::JOINS
            . " WHERE l.id = :id AND {$branchSql}"
            . ($withTrashed ? '' : ' AND l.deleted_at IS NULL'),
            ['id' => $id] + $branchBind,
        );

        return $row ? Lead::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope, bool $withTrashed = false): ?Lead
    {
        [$branchSql, $branchBind] = $scope->whereClause('l.branch_id');
        $row = $this->db->selectOne(
            "SELECT " . self::DETAIL_COLUMNS . " " . self::JOINS
            . " WHERE l.public_id = :pid AND {$branchSql}"
            . ($withTrashed ? '' : ' AND l.deleted_at IS NULL'),
            ['pid' => $publicId] + $branchBind,
        );

        return $row ? Lead::fromRow($row) : null;
    }

    /** @return Page<Lead> */
    public function paginate(ListQuery $q, BranchScope $scope): Page
    {
        [$where, $bind] = $this->buildWhere($q, $scope);

        $total = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM leads l JOIN lead_statuses st ON st.id = l.status_id"
            . " LEFT JOIN lead_sources src ON src.id = l.source_id WHERE {$where}",
            $bind,
        );

        $order = (self::SORT[$q->sort] ?? 'l.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            "SELECT " . self::LIST_COLUMNS . " " . self::JOINS
            . " WHERE {$where} ORDER BY {$order}, l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([Lead::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array<string,int> status key => count, within scope, excluding soft-deleted */
    public function statusCounts(BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('l.branch_id');
        $rows = $this->db->select(
            "SELECT st.key_name, COUNT(*) AS n FROM leads l JOIN lead_statuses st ON st.id = l.status_id
             WHERE {$branchSql} AND l.deleted_at IS NULL GROUP BY st.key_name",
            $bind,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['key_name']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Likely duplicates by phone / alternate phone / email. Scope-limited so a
     * user is never told about leads they cannot see.
     *
     * @return list<array<string,mixed>>
     */
    public function findLikelyDuplicates(
        BranchScope $scope,
        ?string $phone,
        ?string $altPhone,
        ?string $email,
        ?int $excludeId = null,
    ): array {
        $phones = array_values(array_filter(array_unique([$phone, $altPhone]), static fn ($p) => $p !== null && $p !== ''));
        if ($phones === [] && ($email === null || $email === '')) {
            return [];
        }

        [$branchSql, $bind] = $scope->whereClause('l.branch_id');
        $conds = [];

        if ($phones !== []) {
            $primary = $alt = [];
            foreach ($phones as $i => $p) {
                $primary[] = ":pp{$i}";
                $alt[] = ":ap{$i}";
                $bind["pp{$i}"] = $p;
                $bind["ap{$i}"] = $p;
            }
            $conds[] = 'l.phone IN (' . implode(', ', $primary) . ')';
            $conds[] = 'l.alternate_phone IN (' . implode(', ', $alt) . ')';
        }
        if ($email !== null && $email !== '') {
            $conds[] = 'l.email = :email';
            $bind['email'] = $email;
        }

        $sql = "SELECT l.id, l.public_id, l.lead_number, l.name, l.phone, l.alternate_phone, l.email,
                       st.label AS status_label, l.created_at, u.name AS assigned_to_name
                FROM leads l
                JOIN lead_statuses st ON st.id = l.status_id
                LEFT JOIN users u ON u.id = l.assigned_to
                WHERE {$branchSql} AND l.deleted_at IS NULL AND (" . implode(' OR ', $conds) . ')';

        if ($excludeId !== null) {
            $sql .= ' AND l.id <> :exclude';
            $bind['exclude'] = $excludeId;
        }

        return $this->db->select($sql . ' ORDER BY l.created_at DESC LIMIT 20', $bind);
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): int
    {
        return (int) $this->db->insertRow('leads', $data);
    }

    /**
     * Optimistic update: only writes when record_version matches; bumps it.
     *
     * @param array<string,mixed> $changes
     * @return int rows affected (0 => stale or not found within scope)
     */
    public function update(int $id, array $changes, int $expectedVersion, BranchScope $scope): int
    {
        [$branchSql, $branchBind] = $scope->whereClause('branch_id');

        $set = ['record_version = record_version + 1', 'updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id, 'ver' => $expectedVersion] + $branchBind;
        foreach ($changes as $col => $val) {
            $set[] = "`{$col}` = :c_{$col}";
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            "UPDATE leads SET " . implode(', ', $set)
            . " WHERE id = :id AND record_version = :ver AND deleted_at IS NULL AND {$branchSql}",
            $bind,
        );
    }

    public function softDelete(int $id, int $expectedVersion, BranchScope $scope): int
    {
        [$branchSql, $branchBind] = $scope->whereClause('branch_id');

        return $this->db->affectingStatement(
            "UPDATE leads SET deleted_at = UTC_TIMESTAMP(), record_version = record_version + 1
             WHERE id = :id AND record_version = :ver AND deleted_at IS NULL AND {$branchSql}",
            ['id' => $id, 'ver' => $expectedVersion] + $branchBind,
        );
    }

    /** @param list<int> $ids @return int number updated */
    public function bulkAssign(array $ids, ?int $assigneeId, BranchScope $scope): int
    {
        if ($ids === []) {
            return 0;
        }
        [$branchSql, $bind] = $scope->whereClause('branch_id');
        $ph = [];
        foreach (array_values($ids) as $i => $id) {
            $ph[] = ":id{$i}";
            $bind["id{$i}"] = (int) $id;
        }
        $bind['assignee'] = $assigneeId;

        return $this->db->affectingStatement(
            "UPDATE leads SET assigned_to = :assignee, record_version = record_version + 1, updated_at = UTC_TIMESTAMP()
             WHERE id IN (" . implode(', ', $ph) . ") AND deleted_at IS NULL AND converted_candidate_id IS NULL AND {$branchSql}",
            $bind,
        );
    }

    /** @return list<array<string,mixed>> notes newest-first */
    public function notes(int $leadId, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));

        return $this->db->select(
            "SELECT n.id, n.body, n.created_at, u.name AS user_name
             FROM lead_notes n LEFT JOIN users u ON u.id = n.user_id
             WHERE n.lead_id = :id ORDER BY n.id DESC LIMIT {$limit}",
            ['id' => $leadId],
        );
    }

    /** @return list<array<string,mixed>> lead followups newest-first */
    public function followups(int $leadId): array
    {
        return $this->db->select(
            "SELECT f.id, f.due_date, f.due_time, f.channel, f.status, f.outcome, f.completed_at, f.created_at,
                    u.name AS assignee_name
             FROM lead_followups f LEFT JOIN users u ON u.id = f.assigned_to
             WHERE f.lead_id = :id ORDER BY f.due_date DESC, f.id DESC",
            ['id' => $leadId],
        );
    }

    /**
     * Active users that could be assigned work in the given branches.
     *
     * @return list<array{id:int,name:string}>
     */
    public function assignableUsers(BranchScope $scope): array
    {
        if ($scope->orgWide) {
            $rows = $this->db->select(
                "SELECT id, name FROM users WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name LIMIT 500",
            );
        } elseif ($scope->ids === []) {
            return [];
        } else {
            $primary = $branch = [];
            $bind = [];
            foreach ($scope->ids as $i => $id) {
                $primary[] = ":pb{$i}";
                $branch[] = ":bb{$i}";
                $bind["pb{$i}"] = $id;
                $bind["bb{$i}"] = $id;
            }
            $rows = $this->db->select(
                "SELECT DISTINCT u.id, u.name FROM users u
                 LEFT JOIN user_branches ub ON ub.user_id = u.id
                 WHERE u.is_active = 1 AND u.deleted_at IS NULL
                   AND (u.is_org_wide = 1 OR u.primary_branch_id IN (" . implode(', ', $primary) . ")
                        OR ub.branch_id IN (" . implode(', ', $branch) . "))
                 ORDER BY u.name LIMIT 500",
                $bind,
            );
        }

        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /** @return list<array{id:int,key_name:string,label:string,is_terminal:int}> */
    public function statusOptions(): array
    {
        return $this->db->select(
            'SELECT id, key_name, label, is_terminal FROM lead_statuses WHERE is_active = 1 ORDER BY sort_order',
        );
    }

    /** @return list<array{id:int,name:string}> */
    public function sourceOptions(): array
    {
        return $this->db->select('SELECT id, name FROM lead_sources WHERE is_active = 1 ORDER BY sort_order, name');
    }

    public function defaultStatusId(): int
    {
        return (int) $this->db->selectValue(
            "SELECT id FROM lead_statuses WHERE key_name = 'new' AND is_active = 1 LIMIT 1",
            [],
            0,
        );
    }

    public function statusIdByKey(string $key): ?int
    {
        $id = $this->db->selectValue('SELECT id FROM lead_statuses WHERE key_name = :k', ['k' => $key]);

        return $id !== null ? (int) $id : null;
    }

    // ---- internals -------------------------------------------------

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q, BranchScope $scope): array
    {
        [$branchSql, $bind] = $scope->whereClause('l.branch_id');
        $where = [$branchSql, 'l.deleted_at IS NULL'];

        if (($status = $q->filter('status')) !== null) {
            $where[] = 'st.key_name = :f_status';
            $bind['f_status'] = $status;
        }
        if (($src = $q->filter('source')) !== null && ctype_digit($src)) {
            $where[] = 'l.source_id = :f_source';
            $bind['f_source'] = (int) $src;
        }
        if (($assignee = $q->filter('assignee')) !== null && ctype_digit($assignee)) {
            $where[] = 'l.assigned_to = :f_assignee';
            $bind['f_assignee'] = (int) $assignee;
        }
        if ($q->filter('unassigned') === '1') {
            $where[] = 'l.assigned_to IS NULL';
        }
        if (($priority = $q->filter('priority')) !== null
            && in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            $where[] = 'l.priority = :f_priority';
            $bind['f_priority'] = $priority;
        }
        if (($country = $q->filter('country')) !== null && preg_match('/^[A-Za-z]{2}$/', $country)) {
            $where[] = 'l.interested_country = :f_country';
            $bind['f_country'] = strtoupper($country);
        }
        if (($from = $q->filter('from')) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'l.created_at >= :f_from';
            $bind['f_from'] = $from . ' 00:00:00';
        }
        if (($to = $q->filter('to')) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'l.created_at <= :f_to';
            $bind['f_to'] = $to . ' 23:59:59';
        }

        if ($q->hasSearch()) {
            $term = $q->search;
            $prefix = $this->likePrefix($term);
            // Native prepared statements disallow reusing a named placeholder.
            $where[] = '(l.lead_number = :s_exact OR l.phone LIKE :s_phone OR l.alternate_phone LIKE :s_altphone
                        OR l.email LIKE :s_email OR l.name LIKE :s_name)';
            $bind['s_exact'] = $term;
            $bind['s_phone'] = $prefix;
            $bind['s_altphone'] = $prefix;
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
