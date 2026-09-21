<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CandidateExperience;
use App\Support\Db;

/**
 * SQL for `candidate_experience`. Same ownership model as
 * CandidateEducationRepository — no branch column here; the parent candidate
 * is resolved and authorized first, every method still scopes by
 * `candidate_id`.
 */
final class CandidateExperienceRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<CandidateExperience> current/most recent first */
    public function forCandidate(int $candidateId): array
    {
        $rows = $this->db->select(
            'SELECT * FROM candidate_experience WHERE candidate_id = :cid
             ORDER BY is_current DESC, end_date IS NULL DESC, end_date DESC, start_date DESC, id DESC',
            ['cid' => $candidateId],
        );

        return array_map([CandidateExperience::class, 'fromRow'], $rows);
    }

    public function findInCandidate(int $id, int $candidateId): ?CandidateExperience
    {
        $row = $this->db->selectOne(
            'SELECT * FROM candidate_experience WHERE id = :id AND candidate_id = :cid',
            ['id' => $id, 'cid' => $candidateId],
        );

        return $row ? CandidateExperience::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('candidate_experience', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, int $candidateId, array $data): int
    {
        return $this->db->updateRow('candidate_experience', $data, ['id' => $id, 'candidate_id' => $candidateId]);
    }

    public function delete(int $id, int $candidateId): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM candidate_experience WHERE id = :id AND candidate_id = :cid',
            ['id' => $id, 'cid' => $candidateId],
        );
    }
}
