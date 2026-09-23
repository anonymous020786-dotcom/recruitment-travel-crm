<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * `invoice_status_history` is append-only: this repository deliberately exposes no
 * update or delete method. Writers: InvoiceService and PaymentService, inside the
 * transaction that changes the invoice.
 */
final class InvoiceHistoryRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function append(int $invoiceId, ?string $from, string $to, ?string $reason, ?int $changedBy): void
    {
        $this->db->insertRow('invoice_status_history', [
            'invoice_id' => $invoiceId, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'changed_by' => $changedBy,
        ]);
    }

    /** @return list<array{from:?string,to:string,reason:?string,by:?string,at:string}> newest first */
    public function forInvoice(int $invoiceId): array
    {
        $rows = $this->db->select(
            'SELECT h.from_status, h.to_status, h.reason, h.changed_at, u.name AS by_name
             FROM invoice_status_history h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.invoice_id = :id ORDER BY h.id DESC',
            ['id' => $invoiceId],
        );

        return array_map(static fn (array $r): array => [
            'from' => $r['from_status'] ?? null, 'to' => (string) $r['to_status'],
            'reason' => $r['reason'] ?? null, 'by' => $r['by_name'] ?? null, 'at' => (string) $r['changed_at'],
        ], $rows);
    }
}
