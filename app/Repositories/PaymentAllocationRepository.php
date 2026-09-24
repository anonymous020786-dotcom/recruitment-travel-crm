<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * `payment_allocations`: which part of a payment settles which invoice. Rows are never
 * deleted — reversing a payment leaves them in place (the ledger stays complete) and
 * the "effective" queries below only count allocations of payments that are still recorded.
 */
final class PaymentAllocationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{invoice_id:int,invoice_public_id:string,invoice_number:string,amount:string}> */
    public function forPayment(int $paymentId): array
    {
        $rows = $this->db->select(
            'SELECT a.invoice_id, a.amount, i.public_id AS invoice_public_id, i.invoice_number
             FROM payment_allocations a JOIN invoices i ON i.id = a.invoice_id WHERE a.payment_id = :p ORDER BY a.id',
            ['p' => $paymentId],
        );

        return array_map(static fn (array $r): array => [
            'invoice_id' => (int) $r['invoice_id'], 'invoice_public_id' => (string) $r['invoice_public_id'],
            'invoice_number' => (string) $r['invoice_number'], 'amount' => (string) $r['amount'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> payments applied to an invoice, newest first (reversed ones included, flagged) */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->select(
            'SELECT a.amount, p.public_id AS payment_public_id, p.payment_number, p.receipt_number, p.method, p.paid_at, p.status, p.currency
             FROM payment_allocations a JOIN payments p ON p.id = a.payment_id WHERE a.invoice_id = :i ORDER BY a.id DESC',
            ['i' => $invoiceId],
        );
    }

    /** Adds to an existing (payment, invoice) allocation, or creates it. The unique key guarantees one row per pair. */
    public function add(int $paymentId, int $invoiceId, string $amount, int $createdBy): void
    {
        $this->db->affectingStatement(
            'INSERT INTO payment_allocations (payment_id, invoice_id, amount, created_by) VALUES (:p, :i, :a, :u)
             ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)',
            ['p' => $paymentId, 'i' => $invoiceId, 'a' => $amount, 'u' => $createdBy],
        );
    }

    /** Total applied from a payment (regardless of its status; callers check that). */
    public function sumForPayment(int $paymentId): string
    {
        return (string) $this->db->selectValue('SELECT COALESCE(SUM(amount), 0) FROM payment_allocations WHERE payment_id = :p', ['p' => $paymentId], '0');
    }
}
