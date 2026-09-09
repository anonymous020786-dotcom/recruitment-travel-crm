<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

final class LoginAttemptRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function record(string $email, string $ipBinary, bool $successful): void
    {
        $this->db->affectingStatement(
            'INSERT INTO login_attempts (email, ip_address, successful, attempted_at)
             VALUES (:email, :ip, :ok, UTC_TIMESTAMP())',
            ['email' => mb_substr($email, 0, 180), 'ip' => $ipBinary, 'ok' => $successful ? 1 : 0],
        );
    }

    public function recentFailuresByEmail(string $email, int $withinSeconds): int
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND successful = 0
               AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL :secs SECOND)',
            ['email' => $email, 'secs' => $withinSeconds],
            0,
        );
    }

    public function recentFailuresByIp(string $ipBinary, int $withinSeconds): int
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND successful = 0
               AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL :secs SECOND)',
            ['ip' => $ipBinary, 'secs' => $withinSeconds],
            0,
        );
    }

    public function pruneOlderThan(int $days): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM login_attempts WHERE attempted_at < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $days],
        );
    }
}
