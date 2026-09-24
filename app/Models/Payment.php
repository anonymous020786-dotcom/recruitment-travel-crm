<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;

/** A payment received from a person (`payments`). Immutable read model; `allocated` is the sum of its allocations. */
final class Payment
{
    public const METHODS = [
        'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other',
    ];

    /** Methods that leave a trace outside the till, so a reference (UTR, cheque no., txn id) is mandatory. */
    public const NEEDS_REFERENCE = ['bank_transfer', 'upi', 'card', 'cheque'];

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $paymentNumber,
        public readonly string $receiptNumber,
        public readonly int $personId,
        public readonly string $customerName,
        public readonly ?string $customerPhone,
        public readonly int $branchId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $method,
        public readonly ?string $reference,
        public readonly string $paidAt,
        public readonly string $status,
        public readonly ?string $reversedReason,
        public readonly ?string $notes,
        public readonly string $allocated,
        public readonly string $creditRefunded,
        public readonly ?string $receivedBy,
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
            paymentNumber: (string) $r['payment_number'],
            receiptNumber: (string) $r['receipt_number'],
            personId: (int) $r['person_id'],
            customerName: (string) ($r['customer_name'] ?? ''),
            customerPhone: $r['customer_phone'] ?? null,
            branchId: (int) $r['branch_id'],
            amount: (string) $r['amount'],
            currency: (string) $r['currency'],
            method: (string) $r['method'],
            reference: $r['reference'] ?? null,
            paidAt: (string) $r['paid_at'],
            status: (string) $r['status'],
            reversedReason: $r['reversed_reason'] ?? null,
            notes: $r['notes'] ?? null,
            allocated: (string) ($r['allocated'] ?? '0.00'),
            creditRefunded: (string) ($r['credit_refunded'] ?? '0.00'),
            receivedBy: $r['received_by'] ?? null,
            recordVersion: (int) $r['record_version'],
            createdAt: (string) $r['created_at'],
        );
    }

    public function isRecorded(): bool
    {
        return $this->status === 'recorded';
    }

    /**
     * Money received but neither applied to an invoice nor (being) refunded as credit, in minor units
     * (0 for a reversed payment).
     */
    public function unallocatedMinor(): int
    {
        return $this->isRecorded()
            ? max(0, Money::toMinor($this->amount) - Money::toMinor($this->allocated) - Money::toMinor($this->creditRefunded))
            : 0;
    }

    public function unallocated(): string
    {
        return Money::fromMinor($this->unallocatedMinor());
    }

    public function money(string $amount): string
    {
        return $this->currency . ' ' . number_format((float) $amount, 2);
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
