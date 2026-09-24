<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;

/** An invoice (`invoices` joined to person and to its application / tour booking). Immutable read model. */
final class Invoice
{
    /** Statuses in which money is still expected. */
    public const COLLECTIBLE = ['issued', 'partially_paid'];

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $invoiceNumber,
        public readonly int $personId,
        public readonly string $customerName,
        public readonly ?string $customerPhone,
        public readonly int $branchId,
        public readonly string $type,
        public readonly ?int $invoiceableId,
        public readonly ?string $referenceNumber,
        public readonly ?string $referencePublicId,
        public readonly string $currency,
        public readonly string $subtotal,
        public readonly string $discountTotal,
        public readonly string $taxTotal,
        public readonly string $grandTotal,
        public readonly string $amountPaid,
        public readonly string $amountRefunded,
        public readonly string $status,
        public readonly ?string $issuedOn,
        public readonly ?string $dueOn,
        public readonly ?string $notes,
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
            invoiceNumber: (string) $r['invoice_number'],
            personId: (int) $r['person_id'],
            customerName: (string) ($r['customer_name'] ?? ''),
            customerPhone: $r['customer_phone'] ?? null,
            branchId: (int) $r['branch_id'],
            type: (string) $r['invoiceable_type'],
            invoiceableId: isset($r['invoiceable_id']) ? (int) $r['invoiceable_id'] : null,
            referenceNumber: $r['reference_number'] ?? null,
            referencePublicId: $r['reference_public_id'] ?? null,
            currency: (string) $r['currency'],
            subtotal: (string) $r['subtotal'],
            discountTotal: (string) $r['discount_total'],
            taxTotal: (string) $r['tax_total'],
            grandTotal: (string) $r['grand_total'],
            amountPaid: (string) $r['amount_paid'],
            amountRefunded: (string) $r['amount_refunded'],
            status: (string) $r['status'],
            issuedOn: $r['issued_on'] ?? null,
            dueOn: $r['due_on'] ?? null,
            notes: $r['notes'] ?? null,
            recordVersion: (int) $r['record_version'],
            createdAt: (string) $r['created_at'],
        );
    }

    /**
     * The status the money justifies for an invoice that has left draft: net received = paid − refunded;
     * nothing left → issued, everything covered → paid, otherwise partially_paid. Draft / void never change here.
     */
    public static function statusFor(string $current, int $grandMinor, int $paidMinor, int $refundedMinor): string
    {
        if (!in_array($current, ['issued', 'partially_paid', 'paid'], true)) {
            return $current;
        }
        $net = $paidMinor - $refundedMinor;

        return $net <= 0 ? 'issued' : ($net >= $grandMinor ? 'paid' : 'partially_paid');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Money received, net of refunds, in minor units. */
    public function netPaidMinor(): int
    {
        return Money::toMinor($this->amountPaid) - Money::toMinor($this->amountRefunded);
    }

    /** What is still owed, in minor units (never negative). */
    public function outstandingMinor(): int
    {
        return max(0, Money::toMinor($this->grandTotal) - $this->netPaidMinor());
    }

    public function outstanding(): string
    {
        return Money::fromMinor($this->outstandingMinor());
    }

    public function isOverdue(?string $today = null): bool
    {
        return in_array($this->status, self::COLLECTIBLE, true)
            && $this->dueOn !== null
            && $this->dueOn < ($today ?? gmdate('Y-m-d'))
            && $this->outstandingMinor() > 0;
    }

    public function money(string $amount): string
    {
        return $this->currency . ' ' . number_format((float) $amount, 2);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'application'  => 'Recruitment service',
            'tour_booking' => 'Tour booking',
            default        => 'Other',
        };
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->status));
    }
}
