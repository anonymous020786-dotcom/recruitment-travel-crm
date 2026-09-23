<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\TravelProfile;
use App\Support\Db;

/** SQL for `travel_profiles` (one row per candidate, kept by TravelService). */
final class TravelProfileRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function forCandidate(int $candidateId): ?TravelProfile
    {
        $row = $this->db->selectOne('SELECT * FROM travel_profiles WHERE candidate_id = :c ORDER BY id LIMIT 1', ['c' => $candidateId]);

        return $row ? TravelProfile::fromRow($row) : null;
    }

    /**
     * Create the candidate's profile or update the given columns of the existing one.
     *
     * @param array<string,mixed> $set
     */
    public function save(int $candidateId, array $set): void
    {
        $existing = $this->forCandidate($candidateId);
        if ($existing === null) {
            $this->db->insertRow('travel_profiles', ['candidate_id' => $candidateId] + $set);

            return;
        }
        if ($set !== []) {
            $this->db->updateRow('travel_profiles', $set, ['id' => $existing->id]);
        }
    }
}
