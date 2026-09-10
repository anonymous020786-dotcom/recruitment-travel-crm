<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * Persistence for TOTP secrets, recovery codes and one-time email codes.
 */
final class TwoFactorRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ---- TOTP ------------------------------------------------------

    public function storeTotpSecret(int $userId, string $encryptedSecret): void
    {
        $this->db->affectingStatement(
            'UPDATE users SET totp_secret = :s, totp_confirmed_at = NULL WHERE id = :id',
            ['id' => $userId, 's' => $encryptedSecret],
        );
    }

    public function getTotpSecret(int $userId): ?string
    {
        $v = $this->db->selectValue('SELECT totp_secret FROM users WHERE id = :id', ['id' => $userId]);

        return $v === null || $v === '' ? null : (string) $v;
    }

    public function confirmTotp(int $userId): void
    {
        $this->db->affectingStatement(
            "UPDATE users SET totp_confirmed_at = UTC_TIMESTAMP(), two_factor_enabled = 1, two_factor_method = 'totp'
             WHERE id = :id",
            ['id' => $userId],
        );
    }

    public function disable(int $userId): void
    {
        $this->db->transaction(function () use ($userId): void {
            $this->db->affectingStatement(
                "UPDATE users SET totp_secret = NULL, totp_confirmed_at = NULL, two_factor_enabled = 0,
                 two_factor_method = 'none' WHERE id = :id",
                ['id' => $userId],
            );
            $this->db->affectingStatement('DELETE FROM auth_recovery_codes WHERE user_id = :id', ['id' => $userId]);
            $this->db->affectingStatement('DELETE FROM auth_otp_codes WHERE user_id = :id', ['id' => $userId]);
        });
    }

    // ---- Recovery codes -------------------------------------------

    /** @param list<string> $hashes */
    public function replaceRecoveryCodes(int $userId, array $hashes): void
    {
        $this->db->transaction(function () use ($userId, $hashes): void {
            $this->db->affectingStatement('DELETE FROM auth_recovery_codes WHERE user_id = :id', ['id' => $userId]);
            foreach ($hashes as $hash) {
                $this->db->affectingStatement(
                    'INSERT INTO auth_recovery_codes (user_id, code_hash, created_at) VALUES (:id, :h, UTC_TIMESTAMP())',
                    ['id' => $userId, 'h' => $hash],
                );
            }
        });
    }

    public function consumeRecoveryCode(int $userId, string $codeHash): bool
    {
        return $this->db->affectingStatement(
            'UPDATE auth_recovery_codes SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :id AND code_hash = :h AND used_at IS NULL',
            ['id' => $userId, 'h' => $codeHash],
        ) > 0;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM auth_recovery_codes WHERE user_id = :id AND used_at IS NULL',
            ['id' => $userId],
            0,
        );
    }

    // ---- Email / step-up one-time codes -------------------------

    public function createOtp(int $userId, string $purpose, string $codeHash, \DateTimeInterface $expiresAt, ?string $ipBinary): void
    {
        $this->db->transaction(function () use ($userId, $purpose, $codeHash, $expiresAt, $ipBinary): void {
            $this->db->affectingStatement(
                'DELETE FROM auth_otp_codes WHERE user_id = :id AND purpose = :p AND consumed_at IS NULL',
                ['id' => $userId, 'p' => $purpose],
            );
            $this->db->affectingStatement(
                'INSERT INTO auth_otp_codes (user_id, purpose, code_hash, expires_at, created_ip, created_at)
                 VALUES (:id, :p, :h, :exp, :ip, UTC_TIMESTAMP())',
                ['id' => $userId, 'p' => $purpose, 'h' => $codeHash, 'exp' => $expiresAt->format('Y-m-d H:i:s'), 'ip' => $ipBinary],
            );
        });
    }

    /** @return array{id:int,code_hash:string,attempts:int}|null */
    public function latestValidOtp(int $userId, string $purpose): ?array
    {
        $row = $this->db->selectOne(
            'SELECT id, code_hash, attempts FROM auth_otp_codes
             WHERE user_id = :id AND purpose = :p AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC LIMIT 1',
            ['id' => $userId, 'p' => $purpose],
        );

        return $row ? ['id' => (int) $row['id'], 'code_hash' => (string) $row['code_hash'], 'attempts' => (int) $row['attempts']] : null;
    }

    public function incrementOtpAttempts(int $id): void
    {
        $this->db->affectingStatement('UPDATE auth_otp_codes SET attempts = attempts + 1 WHERE id = :id', ['id' => $id]);
    }

    public function consumeOtp(int $id): void
    {
        $this->db->affectingStatement('UPDATE auth_otp_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
    }

    public function lastOtpCreatedAt(int $userId, string $purpose): ?int
    {
        $ts = $this->db->selectValue(
            'SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM auth_otp_codes WHERE user_id = :id AND purpose = :p',
            ['id' => $userId, 'p' => $purpose],
        );

        return $ts === null ? null : (int) $ts;
    }

    public function pruneExpired(): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM auth_otp_codes WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)',
        );
    }
}
