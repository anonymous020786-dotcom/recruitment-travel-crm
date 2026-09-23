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

    /**
     * One query for many jobs.
     *
     * @param list<int> $jobIds
     * @return array<int,list<JobRequirement>> job_id => requirements
     */
    public function forJobs(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }
        $ph = [];
        $bind = [];
        foreach (array_values($jobIds) as $i => $id) {
            $ph[] = ":j{$i}";
            $bind["j{$i}"] = (int) $id;
        }
        $out = [];
        foreach ($this->db->select(
            'SELECT * FROM job_requirements WHERE job_id IN (' . implode(', ', $ph) . ') ORDER BY is_mandatory DESC, weight DESC, id',
            $bind,
        ) as $row) {
            $r = JobRequirement::fromRow($row);
            $out[$r->jobId][] = $r;
        }

        return $out;
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
