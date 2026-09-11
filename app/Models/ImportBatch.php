<?php

declare(strict_types=1);

namespace App\Models;

/** A staged CSV import (`import_batches`). Immutable read model. */
final class ImportBatch
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $entity,
        public readonly string $originalName,
        public readonly string $storagePath,
        public readonly int $totalRows,
        public readonly int $importedRows,
        public readonly int $skippedRows,
        public readonly int $failedRows,
        public readonly string $status,
        /** @var list<string> original CSV header row, by column index */
        public readonly array $headers,
        /** @var array<string,string> csv column index (as string) => lead field */
        public readonly array $mapping,
        public readonly ?string $reportPath,
        public readonly int $branchId,
        public readonly int $createdBy,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        $headers = [];
        $mapping = [];
        if (!empty($r['mapping_json'])) {
            $decoded = json_decode((string) $r['mapping_json'], true);
            if (is_array($decoded)) {
                $headers = is_array($decoded['headers'] ?? null) ? array_map('strval', $decoded['headers']) : [];
                $mapping = is_array($decoded['map'] ?? null) ? $decoded['map'] : [];
            }
        }

        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            entity: (string) $r['entity'],
            originalName: (string) $r['original_name'],
            storagePath: (string) $r['storage_path'],
            totalRows: (int) $r['total_rows'],
            importedRows: (int) $r['imported_rows'],
            skippedRows: (int) ($r['skipped_rows'] ?? 0),
            failedRows: (int) $r['failed_rows'],
            status: (string) $r['status'],
            headers: $headers,
            mapping: $mapping,
            reportPath: $r['report_path'] ?? null,
            branchId: (int) $r['branch_id'],
            createdBy: (int) $r['created_by'],
            createdAt: (string) $r['created_at'],
        );
    }

    public function isPreviewed(): bool
    {
        return $this->status === 'previewed';
    }

    public function isDone(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
