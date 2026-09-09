<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

final class PasswordResetRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(int $userId, string $tokenHash, \DateTimeInterface $expiresAt, string $ipBinary): void
    {
        $this->db->transaction(function () use ($userId, $tokenHash, $expiresAt, $ipBinary): void {
            // One live token per user.
            $this->db->affectingStatement(
                'DELETE FROM password_resets WHERE user_id = :uid',
                ['uid' => $userId],
            );
            $this->db->affectingStatement(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, request_ip, created_at)
                 VALUES (:uid, :hash, :exp, :ip, UTC_TIMESTAMP())',
                ['uid' => $userId, 'hash' => $tokenHash, 'exp' => $expiresAt->format('Y-m-d H:i:s'), 'ip' => $ipBinary],
            );
        });
    }

    /** @return array{id:int,user_id:int}|null */
    public function findValid(string $tokenHash): ?array
    {
        $row = $this->db->selectOne(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            ['hash' => $tokenHash],
        );

        return $row ? ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']] : null;
    }

    public function lastCreatedAtForUser(int $userId): ?int
    {
        $ts = $this->db->selectValue(
            'SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM password_resets WHERE user_id = :uid',
            ['uid' => $userId],
        );

        return $ts === null ? null : (int) $ts;
    }

    public function markUsed(int $id): void
    {
        $this->db->affectingStatement(
            'UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id],
        );
    }

    public function deleteForUser(int $userId): void
    {
        $this->db->affectingStatement('DELETE FROM password_resets WHERE user_id = :uid', ['uid' => $userId]);
    }

    public function pruneExpired(): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() OR used_at IS NOT NULL',
        );
    }
}
