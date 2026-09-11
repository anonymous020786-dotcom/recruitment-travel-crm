<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ImportBatch;
use App\Support\Db;

/**
 * SQL for `import_batches` / `import_rows`. Batches are scoped to the branch
 * they were created for and the user who created them (checked by callers —
 * there is no cross-branch/cross-user visibility for an import in progress).
 */
final class ImportRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function createBatch(array $data): int
    {
        return (int) $this->db->insertRow('import_batches', $data);
    }

    /**
     * A batch is visible only to the user who staged it — its branch_id was
     * already validated against their scope at stage time, and every row is
     * re-checked against scope again on confirm, so ownership alone is the
     * right (and simplest correct) check here.
     */
    public function findBatch(int $id, int $createdBy): ?ImportBatch
    {
        $row = $this->db->selectOne(
            'SELECT * FROM import_batches WHERE id = :id AND created_by = :u',
            ['id' => $id, 'u' => $createdBy],
        );

        return $row ? ImportBatch::fromRow($row) : null;
    }

    public function findBatchByPublicId(string $publicId, int $createdBy): ?ImportBatch
    {
        $row = $this->db->selectOne(
            'SELECT * FROM import_batches WHERE public_id = :pid AND created_by = :u',
            ['pid' => $publicId, 'u' => $createdBy],
        );

        return $row ? ImportBatch::fromRow($row) : null;
    }

    /**
     * @param list<string> $headers
     * @param array<string,string> $mapping
     */
    public function setMapping(int $batchId, array $headers, array $mapping): void
    {
        $this->db->affectingStatement(
            'UPDATE import_batches SET mapping_json = :m WHERE id = :id',
            ['id' => $batchId, 'm' => json_encode(['headers' => $headers, 'map' => $mapping], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        );
    }

    public function setStatus(int $batchId, string $status): void
    {
        $this->db->affectingStatement(
            'UPDATE import_batches SET status = :s WHERE id = :id',
            ['id' => $batchId, 's' => $status],
        );
    }

    /**
     * Finish the batch: final counters, status, and (optionally) the path of a
     * generated per-row error report CSV.
     */
    public function finish(int $batchId, int $imported, int $skipped, int $failed, string $status, ?string $reportPath): void
    {
        $this->db->affectingStatement(
            'UPDATE import_batches
             SET imported_rows = :i, skipped_rows = :s, failed_rows = :f, status = :st, report_path = :rp
             WHERE id = :id',
            ['id' => $batchId, 'i' => $imported, 's' => $skipped, 'f' => $failed, 'st' => $status, 'rp' => $reportPath],
        );
    }

    /** @param list<array<string,mixed>> $rows each becomes one raw_json row */
    public function insertRows(int $batchId, array $rows): void
    {
        $n = 1;
        foreach ($rows as $row) {
            $this->db->insertRow('import_rows', [
                'import_batch_id' => $batchId,
                'row_number'      => $n++,
                'raw_json'        => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /** @return list<array<string,mixed>> ordered by row_number */
    public function sampleRows(int $batchId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));

        return $this->db->select(
            "SELECT id, row_number, raw_json FROM import_rows WHERE import_batch_id = :id ORDER BY row_number LIMIT {$limit}",
            ['id' => $batchId],
        );
    }

    /** @return \Generator<array<string,mixed>> every row, in order — the caller processes and marks each one */
    public function cursorRows(int $batchId): \Generator
    {
        yield from $this->db->cursor(
            'SELECT id, row_number, raw_json FROM import_rows WHERE import_batch_id = :id ORDER BY row_number',
            ['id' => $batchId],
        );
    }

    public function markRow(int $rowId, string $status, ?string $error, ?int $createdRecordId): void
    {
        $this->db->affectingStatement(
            'UPDATE import_rows SET status = :s, error = :e, created_record_id = :r WHERE id = :id',
            ['id' => $rowId, 's' => $status, 'e' => $error !== null ? mb_substr($error, 0, 500) : null, 'r' => $createdRecordId],
        );
    }

    /** @return list<array<string,mixed>> failed/skipped rows, for the error-report CSV */
    public function problemRows(int $batchId): array
    {
        return $this->db->select(
            "SELECT row_number, raw_json, status, error FROM import_rows
             WHERE import_batch_id = :id AND status IN ('skipped', 'failed') ORDER BY row_number",
            ['id' => $batchId],
        );
    }

    /**
     * Delete batches (and, via cascade, their rows) older than N days.
     *
     * @return list<string> storage/report paths the caller should unlink
     */
    public function pruneOlderThan(int $days): array
    {
        $paths = $this->db->select(
            'SELECT storage_path, report_path FROM import_batches WHERE created_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)',
            ['d' => $days],
        );
        $this->db->affectingStatement(
            'DELETE FROM import_batches WHERE created_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)',
            ['d' => $days],
        );

        $out = [];
        foreach ($paths as $p) {
            $out[] = (string) $p['storage_path'];
            if (!empty($p['report_path'])) {
                $out[] = (string) $p['report_path'];
            }
        }

        return $out;
    }
}
