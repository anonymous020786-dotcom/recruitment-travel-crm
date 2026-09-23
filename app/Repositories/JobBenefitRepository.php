<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** SQL for `job_benefits` (plain labels). */
final class JobBenefitRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id:int,label:string}> */
    public function forJob(int $jobId): array
    {
        $rows = $this->db->select('SELECT id, label FROM job_benefits WHERE job_id = :jid ORDER BY id', ['jid' => $jobId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['label']], $rows);
    }

    public function create(int $jobId, string $label): int
    {
        return (int) $this->db->insertRow('job_benefits', ['job_id' => $jobId, 'label' => $label]);
    }

    public function delete(int $id, int $jobId): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM job_benefits WHERE id = :id AND job_id = :jid',
            ['id' => $id, 'jid' => $jobId],
        );
    }
}
