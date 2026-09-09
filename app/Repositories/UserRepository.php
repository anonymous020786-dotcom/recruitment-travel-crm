<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use App\Support\Db;

final class UserRepository
{
    private const SELECT = 'u.id, u.public_id, u.name, u.email, u.role_id, r.name AS role_name,
        u.primary_branch_id, u.is_org_wide, u.is_active, u.locked_until, u.must_change_password';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->selectOne(
            "SELECT " . self::SELECT . " FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL",
            ['id' => $id],
        );

        return $row ? User::fromRow($row) : null;
    }

    public function findActiveById(int $id): ?User
    {
        $user = $this->findById($id);

        return $user !== null && $user->isActive ? $user : null;
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->selectOne(
            "SELECT " . self::SELECT . " FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email AND u.deleted_at IS NULL",
            ['email' => $email],
        );

        return $row ? User::fromRow($row) : null;
    }

    /** The password hash is fetched separately so it never rides along on the User DTO. */
    public function passwordHashFor(int $id): ?string
    {
        $hash = $this->db->selectValue(
            'SELECT password_hash FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );

        return $hash === null ? null : (string) $hash;
    }

    public function failedLoginCount(int $id): int
    {
        return (int) $this->db->selectValue(
            'SELECT failed_login_count FROM users WHERE id = :id',
            ['id' => $id],
            0,
        );
    }

    public function incrementFailedLogins(int $id): int
    {
        $this->db->affectingStatement(
            'UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = :id',
            ['id' => $id],
        );

        return $this->failedLoginCount($id);
    }

    public function resetFailedLogins(int $id): void
    {
        $this->db->affectingStatement(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = :id',
            ['id' => $id],
        );
    }

    public function lockUntil(int $id, \DateTimeInterface $until): void
    {
        $this->db->affectingStatement(
            'UPDATE users SET locked_until = :until WHERE id = :id',
            ['id' => $id, 'until' => $until->format('Y-m-d H:i:s')],
        );
    }

    public function recordSuccessfulLogin(int $id, string $ipBinary): void
    {
        $this->db->affectingStatement(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = :ip,
             failed_login_count = 0, locked_until = NULL WHERE id = :id',
            ['id' => $id, 'ip' => $ipBinary],
        );
    }

    public function updatePasswordHash(int $id, string $hash, bool $clearMustChange = true): void
    {
        $this->db->affectingStatement(
            'UPDATE users SET password_hash = :hash, password_changed_at = UTC_TIMESTAMP()'
            . ($clearMustChange ? ', must_change_password = 0' : '')
            . ' WHERE id = :id',
            ['id' => $id, 'hash' => $hash],
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('users', $data);
    }

    public function emailExists(string $email): bool
    {
        return $this->db->exists('SELECT 1 FROM users WHERE email = :email', ['email' => $email]);
    }
}
