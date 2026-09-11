<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;
use App\Support\Ulid;

/**
 * `persons` — a human identity, shared across leads / candidates / employer
 * contacts. Not branch-scoped: a person is the same individual regardless of
 * which branch is dealing with them.
 */
final class PersonRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM persons WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    /**
     * Match an existing person by phone or email before creating a new one, so
     * the same individual does not accumulate duplicate identities.
     *
     * @param array<string,mixed> $attrs full_name, gender, date_of_birth,
     *        primary_phone, alternate_phone, email, city, state, country
     * @return array{id:int,created:bool}
     */
    public function findOrCreate(array $attrs): array
    {
        $phone = trim((string) ($attrs['primary_phone'] ?? ''));
        $email = trim((string) ($attrs['email'] ?? ''));

        if ($phone !== '') {
            $id = $this->db->selectValue(
                'SELECT id FROM persons WHERE primary_phone = :p AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['p' => $phone],
            );
            if ($id !== null) {
                return ['id' => (int) $id, 'created' => false];
            }
        }
        if ($email !== '') {
            $id = $this->db->selectValue(
                'SELECT id FROM persons WHERE email = :e AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['e' => $email],
            );
            if ($id !== null) {
                return ['id' => (int) $id, 'created' => false];
            }
        }

        $id = (int) $this->db->insertRow('persons', $attrs + ['public_id' => Ulid::generate()]);

        return ['id' => $id, 'created' => true];
    }
}
