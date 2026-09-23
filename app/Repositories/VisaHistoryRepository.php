<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * `visa_status_history` is append-only, like the application history: this
 * repository deliberately exposes no update or delete method, and the only
 * writer is VisaService inside the transition transaction.
 */
final class VisaHistoryRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function append(int $visaId, ?string $from, string $to, bool $isOverride, ?string $reason, int $changedBy): void
    {
        $this->db->insertRow('visa_status_history', [
            'visa_application_id' => $visaId, 'from_status' => $from, 'to_status' => $to,
            'is_override' => $isOverride ? 1 : 0, 'reason' => $reason, 'changed_by' => $changedBy,
        ]);
    }

    /** @return list<array{from:?string,to:string,is_override:bool,reason:?string,by:?string,at:string}> newest first */
    public function forVisa(int $visaId): array
    {
        $rows = $this->db->select(
            'SELECT h.from_status, h.to_status, h.is_override, h.reason, h.changed_at, u.name AS by_name
             FROM visa_status_history h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.visa_application_id = :id ORDER BY h.id DESC',
            ['id' => $visaId],
        );

        return array_map(static fn (array $r): array => [
            'from' => $r['from_status'] ?? null, 'to' => (string) $r['to_status'], 'is_override' => (bool) $r['is_override'],
            'reason' => $r['reason'] ?? null, 'by' => $r['by_name'] ?? null, 'at' => (string) $r['changed_at'],
        ], $rows);
    }
}
