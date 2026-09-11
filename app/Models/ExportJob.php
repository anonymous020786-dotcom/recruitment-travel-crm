<?php

declare(strict_types=1);

namespace App\Models;

/** A queued CSV export (`export_jobs`), drained by cron/process-exports.php. */
final class ExportJob
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $report,
        /** @var array<string,mixed> */
        public readonly array $filters,
        public readonly string $status,
        public readonly ?string $storagePath,
        public readonly ?int $rowCount,
        public readonly int $requestedBy,
        public readonly string $createdAt,
        public readonly ?string $completedAt,
        public readonly ?string $expiresAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        $filters = [];
        if (!empty($r['filters_json'])) {
            $decoded = json_decode((string) $r['filters_json'], true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            report: (string) $r['report'],
            filters: $filters,
            status: (string) $r['status'],
            storagePath: $r['storage_path'] ?? null,
            rowCount: isset($r['row_count']) ? (int) $r['row_count'] : null,
            requestedBy: (int) $r['requested_by'],
            createdAt: (string) $r['created_at'],
            completedAt: $r['completed_at'] ?? null,
            expiresAt: $r['expires_at'] ?? null,
        );
    }

    public function isReady(): bool
    {
        return $this->status === 'completed' && $this->storagePath !== null && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < gmdate('Y-m-d H:i:s');
    }
}
