<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;
use App\Support\Sql;

/**
 * SQL for the admin "Users" screens. Password hashes are never selected here — they only ever leave the database
 * through UserRepository::passwordHashFor() for a login check.
 */
final class UserAdminRepository
{
    public const SORT = ['name' => 'u.name', 'email' => 'u.email', 'role' => 'r.label', 'last_login' => 'u.last_login_at', 'created_at' => 'u.created_at'];
    public const FILTER_KEYS = ['role', 'status'];

    private const COLUMNS = 'u.id, u.public_id, u.name, u.email, u.phone, u.role_id, r.name AS role_name, r.label AS role_label,
        u.primary_branch_id, b.name AS primary_branch, u.is_org_wide, u.is_active, u.locked_until, u.failed_login_count,
        u.last_login_at, u.password_changed_at, u.must_change_password, u.two_factor_enabled, u.created_at';

    private const JOINS = 'FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN branches b ON b.id = u.primary_branch_id';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return Page<array<string,mixed>> */
    public function paginate(ListQuery $q): Page
    {
        $conds = ['u.deleted_at IS NULL'];
        $bind = [];
        if (($role = $q->filter('role')) !== null && $role !== '') {
            $conds[] = 'r.name = :f_role';
            $bind['f_role'] = $role;
        }
        match ($q->filter('status')) {
            'active'   => $conds[] = 'u.is_active = 1',
            'inactive' => $conds[] = 'u.is_active = 0',
            'locked'   => $conds[] = 'u.locked_until IS NOT NULL AND u.locked_until > UTC_TIMESTAMP()',
            default    => null,
        };
        if ($q->hasSearch()) {
            $conds[] = '(u.name LIKE :s_name OR u.email LIKE :s_email OR u.phone LIKE :s_phone)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
            $bind['s_name'] = $bind['s_email'] = $bind['s_phone'] = $like;
        }
        $where = implode(' AND ', $conds);

        $total = (int) $this->db->selectValue('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE ' . $where, $bind);
        $order = (self::SORT[$q->sort] ?? 'u.name') . ' ' . ($q->direction === 'desc' ? 'DESC' : 'ASC');
        $limit = $q->perPage;
        $offset = $q->offset();
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, u.id LIMIT {$limit} OFFSET {$offset}", $bind);

        return new Page($rows, $total, $q->page, $q->perPage);
    }

    /** @return array<string,mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE u.public_id = :p AND u.deleted_at IS NULL', ['p' => $publicId]);
        if ($row !== null) {
            $row['branch_ids'] = $this->branchIds((int) $row['id']);
        }

        return $row;
    }

    /** @return list<int> */
    public function branchIds(int $userId): array
    {
        return array_map('intval', array_column($this->db->select('SELECT branch_id FROM user_branches WHERE user_id = :u ORDER BY branch_id', ['u' => $userId]), 'branch_id'));
    }

    /** @param list<int> $branchIds */
    public function syncBranches(int $userId, array $branchIds): void
    {
        $this->db->affectingStatement('DELETE FROM user_branches WHERE user_id = :u', ['u' => $userId]);
        foreach (array_values(array_unique($branchIds)) as $b) {
            $this->db->insertRow('user_branches', ['user_id' => $userId, 'branch_id' => $b]);
        }
    }

    /** @return list<array{id:int,name:string,label:string}> */
    public function roles(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'label' => (string) $r['label']], $this->db->select('SELECT id, name, label FROM roles ORDER BY id'));
    }

    /** @return list<array{id:int,name:string}> */
    public function branches(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $this->db->select('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name'));
    }

    /** @param array<string,mixed> $changes */
    public function update(int $id, array $changes): void
    {
        $set = ['updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id];
        foreach ($changes as $col => $val) {
            $set[] = Sql::assign((string) $col, 'c_');
            $bind["c_{$col}"] = $val;
        }
        $this->db->affectingStatement('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id AND deleted_at IS NULL', $bind);
    }

    public function activeSuperAdminCount(?int $excludingUserId = null): int
    {
        return (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'super_admin' AND u.is_active = 1 AND u.deleted_at IS NULL AND u.id <> :ex",
            ['ex' => $excludingUserId ?? 0],
        );
    }

    /** @return array{total:int,active:int,inactive:int,locked:int} */
    public function counts(): array
    {
        $r = $this->db->selectOne(
            'SELECT COUNT(*) AS total, SUM(is_active = 1) AS active, SUM(is_active = 0) AS inactive,
                    SUM(locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP()) AS locked
             FROM users WHERE deleted_at IS NULL',
        ) ?? [];

        return ['total' => (int) ($r['total'] ?? 0), 'active' => (int) ($r['active'] ?? 0), 'inactive' => (int) ($r['inactive'] ?? 0), 'locked' => (int) ($r['locked'] ?? 0)];
    }
}
