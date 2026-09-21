<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CandidatePreferences;
use App\Support\Db;

/**
 * SQL for `candidate_preferences` — one row per candidate (PK = candidate_id),
 * created on first save. Ownership is enforced by the caller resolving the
 * branch-scoped candidate first.
 */
final class CandidatePreferencesRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function find(int $candidateId): ?CandidatePreferences
    {
        $row = $this->db->selectOne('SELECT * FROM candidate_preferences WHERE candidate_id = :cid', ['cid' => $candidateId]);

        return $row ? CandidatePreferences::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function upsert(int $candidateId, array $data): void
    {
        $data = ['candidate_id' => $candidateId] + $data;
        $columns = array_keys($data);
        $updateCols = array_diff($columns, ['candidate_id']);

        $sql = sprintf(
            'INSERT INTO candidate_preferences (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
            implode(', ', array_map(static fn (string $c): string => "`{$c}` = VALUES(`{$c}`)", $updateCols)),
        );

        $this->db->affectingStatement($sql, $data);
    }
}
