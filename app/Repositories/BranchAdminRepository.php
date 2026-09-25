<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;
use App\Support\Sql;

/** SQL for Admin → Branches. Branches are organisation-level records, so there is no branch scope here. */
final class BranchAdminRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> every branch with how many active people and live leads it has */
    public function all(): array
    {
        return $this->db->select(
            'SELECT b.id, b.public_id, b.name, b.code, b.city, b.state, b.country, b.phone, b.email, b.is_active,
                    (SELECT COUNT(*) FROM users u WHERE u.primary_branch_id = b.id AND u.is_active = 1 AND u.deleted_at IS NULL) AS people,
                    (SELECT COUNT(*) FROM leads l WHERE l.branch_id = b.id AND l.deleted_at IS NULL) AS leads
             FROM branches b ORDER BY b.is_active DESC, b.name',
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $publicId): ?array
    {
        return $this->db->selectOne('SELECT * FROM branches WHERE public_id = :p', ['p' => $publicId]);
    }

    public function nameExists(string $name, int $exceptId = 0): bool
    {
        return $this->db->exists('SELECT 1 FROM branches WHERE name = :n AND id <> :id', ['n' => $name, 'id' => $exceptId]);
    }

    public function codeExists(string $code, int $exceptId = 0): bool
    {
        return $this->db->exists('SELECT 1 FROM branches WHERE code = :c AND id <> :id', ['c' => $code, 'id' => $exceptId]);
    }

    public function activeCount(): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM branches WHERE is_active = 1');
    }

    /**
     * Active people who would be left with no active branch if this one were switched off: those whose primary branch it is,
     * and those whose only assigned branch it is. Organisation-wide people are not tied to a branch, so they never block.
     */
    public function peopleStrandedBy(int $branchId): int
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(DISTINCT u.id) FROM users u
             WHERE u.is_active = 1 AND u.deleted_at IS NULL AND u.is_org_wide = 0
               AND (u.primary_branch_id = :b1
                    OR (EXISTS (SELECT 1 FROM user_branches ub WHERE ub.user_id = u.id AND ub.branch_id = :b2)
                        AND NOT EXISTS (SELECT 1 FROM user_branches o JOIN branches ob ON ob.id = o.branch_id AND ob.is_active = 1
                                        WHERE o.user_id = u.id AND o.branch_id <> :b3)))',
            ['b1' => $branchId, 'b2' => $branchId, 'b3' => $branchId],
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('branches', $data);
    }

    /** @param array<string,mixed> $data columns to change */
    public function update(int $id, array $data): void
    {
        $sets = [];
        $bind = ['id' => $id];
        foreach ($data as $column => $value) {
            $sets[] = Sql::assign((string) $column, 'c_');
            $bind['c_' . $column] = $value;
        }
        $this->db->affectingStatement('UPDATE branches SET ' . implode(', ', $sets) . ' WHERE id = :id', $bind);
    }
}
