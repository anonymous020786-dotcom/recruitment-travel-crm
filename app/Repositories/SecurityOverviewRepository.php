<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** The read side of Admin → Security: who is signed in where, and who has been failing to sign in. */
final class SecurityOverviewRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Sessions of signed-in people that are still inside the session lifetime, most recently active first.
     *
     * @return list<array<string,mixed>>
     */
    public function activeSessions(int $lifetimeSeconds, int $limit = 300): array
    {
        return $this->db->select(
            'SELECT s.id, s.ip_address, s.user_agent, s.last_activity, s.created_at,
                    u.public_id AS user_public_id, u.name AS user_name, u.email AS user_email, r.label AS role_label
             FROM sessions s JOIN users u ON u.id = s.user_id JOIN roles r ON r.id = u.role_id
             WHERE s.user_id IS NOT NULL AND s.last_activity >= :cut
             ORDER BY s.last_activity DESC LIMIT ' . max(1, min(1000, $limit)),
            ['cut' => time() - $lifetimeSeconds],
        );
    }

    /** @return array{sessions:int,people:int} */
    public function sessionCounts(int $lifetimeSeconds): array
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n, COUNT(DISTINCT user_id) AS p FROM sessions WHERE user_id IS NOT NULL AND last_activity >= :cut',
            ['cut' => time() - $lifetimeSeconds],
        ) ?? [];

        return ['sessions' => (int) ($row['n'] ?? 0), 'people' => (int) ($row['p'] ?? 0)];
    }

    /** The session row (any age) for a hashed id, with its owner — used to sign one session out. @return array<string,mixed>|null */
    public function session(string $id): ?array
    {
        return $this->db->selectOne('SELECT id, user_id FROM sessions WHERE id = :id AND user_id IS NOT NULL', ['id' => $id]);
    }

    /** Sign every other person out: their sessions and "remember me" logins. Your own ($keepId / $keepUserId) stay. @return int sessions ended */
    public function endAllExcept(string $keepId, int $keepUserId): int
    {
        $n = $this->db->affectingStatement('DELETE FROM sessions WHERE user_id IS NOT NULL AND id <> :keep', ['keep' => $keepId]);
        $this->db->affectingStatement('DELETE FROM auth_tokens WHERE user_id <> :me', ['me' => $keepUserId]);

        return $n;
    }

    /** @return array{failed:int,succeeded:int,addresses:int} over the last N hours */
    public function attemptCounts(int $hours): array
    {
        $row = $this->db->selectOne(
            'SELECT COALESCE(SUM(successful = 0), 0) AS f, COALESCE(SUM(successful = 1), 0) AS s, COUNT(DISTINCT CASE WHEN successful = 0 THEN ip_address END) AS a
             FROM login_attempts WHERE attempted_at >= (UTC_TIMESTAMP() - INTERVAL :h HOUR)',
            ['h' => $hours],
        ) ?? [];

        return ['failed' => (int) ($row['f'] ?? 0), 'succeeded' => (int) ($row['s'] ?? 0), 'addresses' => (int) ($row['a'] ?? 0)];
    }

    /** Addresses with the most failed sign-ins. @return list<array{ip_address:string,failures:int,emails:int,last_at:string}> */
    public function topFailingAddresses(int $hours, int $limit = 10): array
    {
        return $this->db->select(
            'SELECT ip_address, COUNT(*) AS failures, COUNT(DISTINCT email) AS emails, MAX(attempted_at) AS last_at
             FROM login_attempts WHERE successful = 0 AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL :h HOUR)
             GROUP BY ip_address ORDER BY failures DESC, last_at DESC LIMIT ' . max(1, min(50, $limit)),
            ['h' => $hours],
        );
    }

    /** @return list<array{email:string,ip_address:string,successful:int,attempted_at:string}> */
    public function recentAttempts(int $limit = 40, bool $failuresOnly = true): array
    {
        return $this->db->select(
            'SELECT email, ip_address, successful, attempted_at FROM login_attempts ' . ($failuresOnly ? 'WHERE successful = 0 ' : '')
            . 'ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
        );
    }

    /** Locked accounts right now. */
    public function lockedAccounts(): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP()', [], 0);
    }
}
