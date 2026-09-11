<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ExportJob;
use App\Support\Db;

/** SQL for `export_jobs`, drained by cron/process-exports.php. */
final class ExportRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('export_jobs', $data);
    }

    public function findByPublicId(string $publicId): ?ExportJob
    {
        $row = $this->db->selectOne('SELECT * FROM export_jobs WHERE public_id = :pid', ['pid' => $publicId]);

        return $row ? ExportJob::fromRow($row) : null;
    }

    /** @return list<ExportJob> most recent first */
    public function forUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $rows = $this->db->select(
            "SELECT * FROM export_jobs WHERE requested_by = :u ORDER BY created_at DESC LIMIT {$limit}",
            ['u' => $userId],
        );

        return array_map([ExportJob::class, 'fromRow'], $rows);
    }

    /** @return list<array{id:int,report:string,filters_json:?string,requested_by:int}> claimed for processing */
    public function claimPending(int $limit): array
    {
        // No SKIP LOCKED on MariaDB < 10.6 in general use here — this cron is
        // single-instance (CronRunner's advisory lock), so a plain claim is safe.
        $rows = $this->db->select(
            "SELECT id, report, filters_json, requested_by FROM export_jobs WHERE status = 'pending' ORDER BY created_at LIMIT " . max(1, min($limit, 50)),
        );
        foreach ($rows as $r) {
            $this->db->affectingStatement(
                "UPDATE export_jobs SET status = 'processing' WHERE id = :id AND status = 'pending'",
                ['id' => $r['id']],
            );
        }

        return $rows;
    }

    public function markCompleted(int $id, string $storagePath, int $rowCount, \DateTimeInterface $expiresAt): void
    {
        $this->db->affectingStatement(
            "UPDATE export_jobs SET status = 'completed', storage_path = :p, row_count = :n,
             completed_at = UTC_TIMESTAMP(), expires_at = :exp WHERE id = :id",
            ['id' => $id, 'p' => $storagePath, 'n' => $rowCount, 'exp' => $expiresAt->format('Y-m-d H:i:s')],
        );
    }

    public function markFailed(int $id): void
    {
        $this->db->affectingStatement(
            "UPDATE export_jobs SET status = 'failed', completed_at = UTC_TIMESTAMP() WHERE id = :id",
            ['id' => $id],
        );
    }

    /** @return list<string> storage paths the caller should unlink */
    public function pruneExpired(): array
    {
        $paths = $this->db->select(
            "SELECT storage_path FROM export_jobs WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP() AND storage_path IS NOT NULL",
        );
        $this->db->affectingStatement(
            "UPDATE export_jobs SET storage_path = NULL WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()",
        );

        return array_map(static fn (array $p): string => (string) $p['storage_path'], $paths);
    }
}
