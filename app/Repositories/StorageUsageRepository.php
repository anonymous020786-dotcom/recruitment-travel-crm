<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** Read-only numbers about stored documents, for Admin → Storage and its cost estimate. */
final class StorageUsageRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,array{count:int,bytes:int}> per storage disk */
    public function byDisk(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT storage_disk, COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS b FROM candidate_documents GROUP BY storage_disk') as $r) {
            $out[(string) $r['storage_disk']] = ['count' => (int) $r['n'], 'bytes' => (int) $r['b']];
        }

        return $out;
    }

    /** Bytes of documents uploaded more than `$days` days ago — the share a lifecycle rule would move to a cheaper class. */
    public function bytesOlderThan(int $days): int
    {
        return (int) $this->db->selectValue('SELECT COALESCE(SUM(size_bytes), 0) FROM candidate_documents WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)', ['d' => max(1, $days)]);
    }

    /** Average uploads per month over the last 90 days. */
    public function uploadsPerMonth(): int
    {
        return (int) round((int) $this->db->selectValue('SELECT COUNT(*) FROM candidate_documents WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)') / 3);
    }

    /** Downloads and previews per month over the last 90 days. */
    public function readsPerMonth(): int
    {
        return (int) round((int) $this->db->selectValue("SELECT COUNT(*) FROM document_access_log WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)") / 3);
    }
}
