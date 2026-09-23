<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\EmployerContact;
use App\Support\Db;

/** SQL for `employer_contacts`; ownership is enforced by resolving the scoped employer first, every write also scopes by `employer_id`. */
final class EmployerContactRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<EmployerContact> primary first */
    public function forEmployer(int $employerId): array
    {
        $rows = $this->db->select(
            'SELECT * FROM employer_contacts WHERE employer_id = :eid ORDER BY is_primary DESC, name',
            ['eid' => $employerId],
        );

        return array_map([EmployerContact::class, 'fromRow'], $rows);
    }

    public function findInEmployer(int $id, int $employerId): ?EmployerContact
    {
        $row = $this->db->selectOne(
            'SELECT * FROM employer_contacts WHERE id = :id AND employer_id = :eid',
            ['id' => $id, 'eid' => $employerId],
        );

        return $row ? EmployerContact::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('employer_contacts', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, int $employerId, array $data): int
    {
        return $this->db->updateRow('employer_contacts', $data, ['id' => $id, 'employer_id' => $employerId]);
    }

    public function delete(int $id, int $employerId): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM employer_contacts WHERE id = :id AND employer_id = :eid',
            ['id' => $id, 'eid' => $employerId],
        );
    }

    public function clearPrimaryExcept(int $employerId, ?int $exceptId): void
    {
        $sql = 'UPDATE employer_contacts SET is_primary = 0 WHERE employer_id = :eid';
        $bind = ['eid' => $employerId];
        if ($exceptId !== null) {
            $sql .= ' AND id != :ex';
            $bind['ex'] = $exceptId;
        }
        $this->db->affectingStatement($sql, $bind);
    }
}
