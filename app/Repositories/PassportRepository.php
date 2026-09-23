<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Passport;
use App\Support\Db;

/**
 * SQL for `passports`. `passport_number` is globally unique (a physical
 * document, not per-candidate), so lookups for that uniqueness check span the
 * whole table; every other method scopes by `candidate_id` — ownership is
 * enforced upstream by resolving the branch-scoped candidate first.
 */
final class PassportRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<Passport> primary first, then soonest expiry */
    public function forCandidate(int $candidateId): array
    {
        $rows = $this->db->select(
            'SELECT * FROM passports WHERE candidate_id = :cid
             ORDER BY is_primary DESC, expiry_date IS NULL, expiry_date ASC, id DESC',
            ['cid' => $candidateId],
        );

        return array_map([Passport::class, 'fromRow'], $rows);
    }

    public function findInCandidate(int $id, int $candidateId): ?Passport
    {
        $row = $this->db->selectOne(
            'SELECT * FROM passports WHERE id = :id AND candidate_id = :cid',
            ['id' => $id, 'cid' => $candidateId],
        );

        return $row ? Passport::fromRow($row) : null;
    }

    /** True if another passport already carries this number (excluding $excludeId on an update). */
    public function numberTaken(string $number, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM passports WHERE passport_number = :n';
        $bind = ['n' => $number];
        if ($excludeId !== null) {
            $sql .= ' AND id != :ex';
            $bind['ex'] = $excludeId;
        }

        return $this->db->exists($sql, $bind);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('passports', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, int $candidateId, array $data): int
    {
        return $this->db->updateRow('passports', $data, ['id' => $id, 'candidate_id' => $candidateId]);
    }

    public function delete(int $id, int $candidateId): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM passports WHERE id = :id AND candidate_id = :cid',
            ['id' => $id, 'cid' => $candidateId],
        );
    }

    /** Clears the primary flag on every OTHER passport of this candidate. */
    public function clearPrimaryExcept(int $candidateId, ?int $exceptId): void
    {
        $sql = 'UPDATE passports SET is_primary = 0 WHERE candidate_id = :cid';
        $bind = ['cid' => $candidateId];
        if ($exceptId !== null) {
            $sql .= ' AND id != :ex';
            $bind['ex'] = $exceptId;
        }
        $this->db->affectingStatement($sql, $bind);
    }

    /**
     * Passports at or inside the widest reminder window, for candidates who still
     * have a live application.
     *
     * @return list<array<string,mixed>> id, candidate_id, candidate_name, passport_number, expiry_date, owner_id
     */
    public function dueForReminder(int $maxDays, string $today): array
    {
        return $this->db->select(
            "SELECT pp.id, pp.candidate_id, pp.passport_number, pp.expiry_date, pe.full_name AS candidate_name, c.assigned_counselor AS owner_id
             FROM passports pp
             JOIN candidates c ON c.id = pp.candidate_id
             JOIN persons pe ON pe.id = c.person_id
             WHERE pp.expiry_date IS NOT NULL AND pp.expiry_date <= (:today + INTERVAL :days DAY)
               AND EXISTS (SELECT 1 FROM applications a WHERE a.candidate_id = pp.candidate_id AND a.status NOT IN ('placed','rejected','cancelled'))
             ORDER BY pp.expiry_date",
            ['today' => $today, 'days' => $maxDays],
        );
    }
}
