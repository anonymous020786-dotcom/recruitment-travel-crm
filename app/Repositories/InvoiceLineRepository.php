<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** SQL for `invoice_lines`. Lines only change while their invoice is a draft (enforced by InvoiceService). */
final class InvoiceLineRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id:int,description:string,quantity:string,unit_price:string,line_total:string}> */
    public function forInvoice(int $invoiceId): array
    {
        $rows = $this->db->select(
            'SELECT id, description, quantity, unit_price, line_total FROM invoice_lines WHERE invoice_id = :i ORDER BY id',
            ['i' => $invoiceId],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'description' => (string) $r['description'], 'quantity' => (string) $r['quantity'],
            'unit_price' => (string) $r['unit_price'], 'line_total' => (string) $r['line_total'],
        ], $rows);
    }

    /** @param list<array{description:string,quantity:string,unit_price:string,line_total:string}> $lines */
    public function replace(int $invoiceId, array $lines): void
    {
        $this->db->affectingStatement('DELETE FROM invoice_lines WHERE invoice_id = :i', ['i' => $invoiceId]);
        foreach ($lines as $l) {
            $this->db->insertRow('invoice_lines', [
                'invoice_id' => $invoiceId, 'description' => $l['description'], 'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'],
            ]);
        }
    }

    public function count(int $invoiceId): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM invoice_lines WHERE invoice_id = :i', ['i' => $invoiceId]);
    }
}
