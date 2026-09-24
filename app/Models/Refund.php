<?php

declare(strict_types=1);

namespace App\Models;

/** A refund of (part of) a payment (`refunds` joined to payment / person / invoice). Immutable read model. */
final class Refund
{
    public const METHODS = [
        'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'card' => 'Card', 'cheque' => 'Cheque', 'adjustment' => 'Adjustment (no cash)',
    ];

    /** Statuses that still reserve part of the payment (money is, or is about to be, going back). */
    public const ACTIVE = ['pending', 'approved', 'paid'];

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $refundNumber,
        public readonly int $paymentId,
        public readonly string $paymentPublicId,
        public readonly string $paymentNumber,
        public readonly ?int $invoiceId,
        public readonly ?string $invoicePublicId,
        public readonly ?string $invoiceNumber,
        public readonly int $personId,
        public readonly string $customerName,
        public readonly int $branchId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $method,
        public readonly string $reason,
        public readonly string $status,
        public readonly ?int $createdBy,
        public readonly ?string $requestedBy,
        public readonly ?string $approvedBy,
        public readonly ?string $approvedAt,
        public readonly ?string $refundedAt,
        public readonly int $recordVersion,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            refundNumber: (string) $r['refund_number'],
            paymentId: (int) $r['payment_id'],
            paymentPublicId: (string) ($r['payment_public_id'] ?? ''),
            paymentNumber: (string) ($r['payment_number'] ?? ''),
            invoiceId: isset($r['invoice_id']) ? (int) $r['invoice_id'] : null,
            invoicePublicId: $r['invoice_public_id'] ?? null,
            invoiceNumber: $r['invoice_number'] ?? null,
            personId: (int) $r['person_id'],
            customerName: (string) ($r['customer_name'] ?? ''),
            branchId: (int) $r['branch_id'],
            amount: (string) $r['amount'],
            currency: (string) $r['currency'],
            method: (string) $r['method'],
            reason: (string) $r['reason'],
            status: (string) $r['status'],
            createdBy: isset($r['created_by']) ? (int) $r['created_by'] : null,
            requestedBy: $r['requested_by'] ?? null,
            approvedBy: $r['approved_by_name'] ?? null,
            approvedAt: $r['approved_at'] ?? null,
            refundedAt: $r['refunded_at'] ?? null,
            recordVersion: (int) $r['record_version'],
            createdAt: (string) $r['created_at'],
        );
    }

    public function money(): string
    {
        return $this->currency . ' ' . number_format((float) $this->amount, 2);
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? ucfirst($this->method);
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}
