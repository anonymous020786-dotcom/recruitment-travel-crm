<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CandidateSkill;
use App\Support\Db;

/**
 * SQL for the `candidate_skills` pivot (composite PK: candidate_id + skill_id).
 * Ownership is enforced by the caller resolving the branch-scoped candidate
 * first; every method here still scopes by `candidate_id`.
 */
final class CandidateSkillRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<CandidateSkill> */
    public function forCandidate(int $candidateId): array
    {
        $rows = $this->db->select(
            'SELECT cs.candidate_id, cs.skill_id, cs.proficiency, cs.years, s.name, s.category
             FROM candidate_skills cs JOIN skills s ON s.id = cs.skill_id
             WHERE cs.candidate_id = :cid ORDER BY s.name',
            ['cid' => $candidateId],
        );

        return array_map([CandidateSkill::class, 'fromRow'], $rows);
    }

    public function exists(int $candidateId, int $skillId): bool
    {
        return $this->db->exists(
            'SELECT 1 FROM candidate_skills WHERE candidate_id = :cid AND skill_id = :sid',
            ['cid' => $candidateId, 'sid' => $skillId],
        );
    }

    public function attach(int $candidateId, int $skillId, string $proficiency, ?float $years): void
    {
        $this->db->affectingStatement(
            'INSERT INTO candidate_skills (candidate_id, skill_id, proficiency, years)
             VALUES (:cid, :sid, :p, :y)
             ON DUPLICATE KEY UPDATE proficiency = VALUES(proficiency), years = VALUES(years)',
            ['cid' => $candidateId, 'sid' => $skillId, 'p' => $proficiency, 'y' => $years],
        );
    }

    public function detach(int $candidateId, int $skillId): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM candidate_skills WHERE candidate_id = :cid AND skill_id = :sid',
            ['cid' => $candidateId, 'sid' => $skillId],
        );
    }
}
