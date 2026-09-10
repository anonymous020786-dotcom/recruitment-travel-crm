<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * Persistence for WebAuthn / passkey credentials. Credential ids and COSE
 * public keys are stored as raw bytes (VARBINARY / BLOB).
 */
final class WebAuthnCredentialRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT id, credential_id, sign_count, transports, label, aaguid, created_at, last_used_at
             FROM webauthn_credentials WHERE user_id = :u ORDER BY created_at DESC',
            ['u' => $userId],
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = :u',
            ['u' => $userId],
            0,
        );
    }

    public function userHasAny(int $userId): bool
    {
        return $this->db->exists('SELECT 1 FROM webauthn_credentials WHERE user_id = :u', ['u' => $userId]);
    }

    /** @return array<string,mixed>|null */
    public function findByCredentialId(string $credentialId): ?array
    {
        return $this->db->selectOne(
            'SELECT id, user_id, credential_id, public_key, sign_count, label
             FROM webauthn_credentials WHERE credential_id = :c',
            ['c' => $credentialId],
        );
    }

    public function create(
        int $userId,
        string $credentialId,
        string $coseKey,
        int $signCount,
        ?string $transports,
        ?string $aaguid,
        string $label,
    ): int {
        $zeroAaguid = str_repeat("\x00", 16);

        return (int) $this->db->insertRow('webauthn_credentials', [
            'user_id'       => $userId,
            'credential_id' => $credentialId,
            'public_key'    => $coseKey,
            'sign_count'    => $signCount,
            'transports'    => $transports,
            'aaguid'        => ($aaguid === null || $aaguid === $zeroAaguid) ? null : $aaguid,
            'label'         => $label,
        ]);
    }

    public function markUsed(int $id, int $signCount): void
    {
        $this->db->affectingStatement(
            'UPDATE webauthn_credentials SET sign_count = :s, last_used_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id, 's' => $signCount],
        );
    }

    public function deleteForUser(int $userId, int $id): bool
    {
        return $this->db->affectingStatement(
            'DELETE FROM webauthn_credentials WHERE id = :id AND user_id = :u',
            ['id' => $id, 'u' => $userId],
        ) > 0;
    }

    public function renameForUser(int $userId, int $id, string $label): bool
    {
        return $this->db->affectingStatement(
            'UPDATE webauthn_credentials SET label = :l WHERE id = :id AND user_id = :u',
            ['id' => $id, 'u' => $userId, 'l' => $label],
        ) > 0;
    }
}
