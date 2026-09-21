<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CandidateEducation;
use App\Support\Db;

/**
 * SQL for `candidate_education`. Rows have no branch column of their own —
 * access control is enforced upstream by resolving the parent candidate
 * through the branch-scoped `CandidateRepository` first; every method here
 * additionally scopes by `candidate_id` so one candidate's rows can never be
 * read or mutated through another's id.
 */
final class CandidateEducationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<CandidateEducation> newest first */
    public function forCandidate(int $candidateId): array
    {
        $rows = $this->db->select(
            'SELECT * FROM candidate_education WHERE candidate_id = :cid
             ORDER BY end_year IS NULL DESC, end_year DESC, start_year DESC, id DESC',
            ['cid' => $candidateId],
        );

        return array_map([CandidateEducation::class, 'fromRow'], $rows);
    }

    public function findInCandidate(int $id, int $candidateId): ?CandidateEducation
    {
        $row = $this->db->selectOne(
            'SELECT * FROM candidate_education WHERE id = :id AND candidate_id = :cid',
            ['id' => $id, 'cid' => $candidateId],
        );

        return $row ? CandidateEducation::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('candidate_education', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, int $candidateId, array $data): int
    {
        return $this->db->updateRow('candidate_education', $data, ['id' => $id, 'candidate_id' => $candidateId]);
    }

    public function delete(int $id, int $candidateId): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM candidate_education WHERE id = :id AND candidate_id = :cid',
            ['id' => $id, 'cid' => $candidateId],
        );
    }
}
