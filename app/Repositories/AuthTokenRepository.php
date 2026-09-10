<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * Persistent-login ("remember me") tokens. Selector/validator split:
 * the selector is the lookup key, the validator is compared with hash_equals.
 */
final class AuthTokenRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(
        int $userId,
        string $series,
        string $selector,
        string $validatorHash,
        \DateTimeInterface $expiresAt,
        ?string $ipBinary,
        ?string $userAgent,
    ): void {
        $this->db->affectingStatement(
            'INSERT INTO auth_tokens (user_id, series, selector, validator_hash, expires_at, created_ip, created_ua, created_at)
             VALUES (:uid, :series, :selector, :vhash, :exp, :ip, :ua, UTC_TIMESTAMP())',
            [
                'uid' => $userId, 'series' => $series, 'selector' => $selector, 'vhash' => $validatorHash,
                'exp' => $expiresAt->format('Y-m-d H:i:s'),
                'ip' => $ipBinary, 'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            ],
        );
    }

    /** @return array{id:int,user_id:int,series:string,validator_hash:string}|null */
    public function findBySelector(string $selector): ?array
    {
        $row = $this->db->selectOne(
            'SELECT id, user_id, series, validator_hash FROM auth_tokens
             WHERE selector = :s AND expires_at > UTC_TIMESTAMP()',
            ['s' => $selector],
        );

        return $row ? [
            'id' => (int) $row['id'], 'user_id' => (int) $row['user_id'],
            'series' => (string) $row['series'], 'validator_hash' => (string) $row['validator_hash'],
        ] : null;
    }

    public function rotate(int $id, string $newValidatorHash, \DateTimeInterface $expiresAt): void
    {
        $this->db->affectingStatement(
            'UPDATE auth_tokens SET validator_hash = :vh, expires_at = :exp, last_used_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 'vh' => $newValidatorHash, 'exp' => $expiresAt->format('Y-m-d H:i:s')],
        );
    }

    public function deleteBySeries(string $series): void
    {
        $this->db->affectingStatement('DELETE FROM auth_tokens WHERE series = :s', ['s' => $series]);
    }

    public function deleteForUser(int $userId): void
    {
        $this->db->affectingStatement('DELETE FROM auth_tokens WHERE user_id = :uid', ['uid' => $userId]);
    }

    public function deleteById(int $id): void
    {
        $this->db->affectingStatement('DELETE FROM auth_tokens WHERE id = :id', ['id' => $id]);
    }

    public function pruneExpired(): int
    {
        return $this->db->affectingStatement('DELETE FROM auth_tokens WHERE expires_at < UTC_TIMESTAMP()');
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT id, series, created_ip, created_ua, last_used_at, expires_at, created_at
             FROM auth_tokens WHERE user_id = :uid ORDER BY created_at DESC',
            ['uid' => $userId],
        );
    }
}
