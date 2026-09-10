<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

final class TrustedDeviceRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(
        int $userId,
        string $tokenHash,
        ?string $label,
        ?string $uaHash,
        ?string $ipBinary,
        \DateTimeInterface $trustedUntil,
    ): void {
        $this->db->affectingStatement(
            'INSERT INTO trusted_devices (user_id, token_hash, label, ua_hash, last_ip, trusted_until, last_seen_at, created_at)
             VALUES (:uid, :th, :label, :ua, :ip, :until, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'uid' => $userId, 'th' => $tokenHash,
                'label' => $label !== null ? mb_substr($label, 0, 120) : null,
                'ua' => $uaHash, 'ip' => $ipBinary,
                'until' => $trustedUntil->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function isTrusted(int $userId, string $tokenHash): bool
    {
        return $this->db->exists(
            'SELECT 1 FROM trusted_devices
             WHERE user_id = :uid AND token_hash = :th AND trusted_until > UTC_TIMESTAMP()',
            ['uid' => $userId, 'th' => $tokenHash],
        );
    }

    public function touch(string $tokenHash, ?string $ipBinary): void
    {
        $this->db->affectingStatement(
            'UPDATE trusted_devices SET last_seen_at = UTC_TIMESTAMP(), last_ip = :ip WHERE token_hash = :th',
            ['th' => $tokenHash, 'ip' => $ipBinary],
        );
    }

    public function deleteByHash(int $userId, string $tokenHash): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM trusted_devices WHERE user_id = :uid AND token_hash = :th',
            ['uid' => $userId, 'th' => $tokenHash],
        );
    }

    public function deleteById(int $userId, int $id): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM trusted_devices WHERE user_id = :uid AND id = :id',
            ['uid' => $userId, 'id' => $id],
        );
    }

    public function deleteForUser(int $userId): void
    {
        $this->db->affectingStatement('DELETE FROM trusted_devices WHERE user_id = :uid', ['uid' => $userId]);
    }

    public function pruneExpired(): int
    {
        return $this->db->affectingStatement('DELETE FROM trusted_devices WHERE trusted_until < UTC_TIMESTAMP()');
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT id, label, last_ip, trusted_until, last_seen_at, created_at
             FROM trusted_devices WHERE user_id = :uid ORDER BY last_seen_at DESC',
            ['uid' => $userId],
        );
    }
}
