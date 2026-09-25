<?php

declare(strict_types=1);

namespace App\Payments;

/** A verified message about one payment. */
final class PaymentEvent
{
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const PENDING = 'pending';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $reference,
        public readonly ?string $providerOrderId,
        public readonly ?string $providerPaymentId,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        /** how the customer paid, mapped to our payment methods: upi | card | bank_transfer | other */
        public readonly string $method = 'other',
        public readonly ?string $reason = null,
    ) {
    }
}
