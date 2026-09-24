<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * `receipts` are immutable: each holds a JSON snapshot of the payment as it was
 * when it was received. This repository deliberately exposes no update or delete
 * method; the only writer is PaymentService inside the payment's transaction.
 */
final class ReceiptRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string,mixed> $snapshot */
    public function issue(string $receiptNumber, int $paymentId, int $issuedBy, array $snapshot): int
    {
        return (int) $this->db->insertRow('receipts', [
            'receipt_number' => $receiptNumber,
            'payment_id'     => $paymentId,
            'issued_by'      => $issuedBy,
            'snapshot_json'  => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array{receipt_number:string,issued_at:string,snapshot:array<string,mixed>}|null */
    public function forPayment(int $paymentId): ?array
    {
        $row = $this->db->selectOne('SELECT receipt_number, issued_at, snapshot_json FROM receipts WHERE payment_id = :p ORDER BY id LIMIT 1', ['p' => $paymentId]);
        if ($row === null) {
            return null;
        }
        $snapshot = json_decode((string) $row['snapshot_json'], true);

        return [
            'receipt_number' => (string) $row['receipt_number'],
            'issued_at'      => (string) $row['issued_at'],
            'snapshot'       => is_array($snapshot) ? $snapshot : [],
        ];
    }
}
