<?php

declare(strict_types=1);

namespace App\Payments;

/** What we ask a gateway to collect. `reference` is our own merchant order id (unique, ≤ 24 characters). */
final class CheckoutRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $description,
        public readonly string $customerName,
        public readonly ?string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly string $returnUrl,
        public readonly string $callbackUrl,
    ) {
    }

    /** The amount as a decimal string in major units, e.g. 1234 minor → "12.34". */
    public function amount(): string
    {
        return \App\Support\Money::fromMinor($this->amountMinor);
    }
}
