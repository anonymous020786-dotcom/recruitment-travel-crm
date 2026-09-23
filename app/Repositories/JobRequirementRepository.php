<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\JobRequirement;
use App\Support\Db;

/** SQL for `job_requirements`; every write scopes by `job_id` (the job is resolved through the scoped JobRepository first). */
final class JobRequirementRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<JobRequirement> mandatory first, then heaviest */
    public function forJob(int $jobId): array
    {
        $rows = $this->db->select(
            'SELECT * FROM job_requirements WHERE job_id = :jid ORDER BY is_mandatory DESC, weight DESC, id',
            ['jid' => $jobId],
        );

        return array_map([JobRequirement::class, 'fromRow'], $rows);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('job_requirements', $data);
    }

    public function delete(int $id, int $jobId): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM job_requirements WHERE id = :id AND job_id = :jid',
            ['id' => $id, 'jid' => $jobId],
        );
    }
}
