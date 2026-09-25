<?php

declare(strict_types=1);

namespace App\Payments;

/** Where to send the customer: a plain redirect, or an auto-submitted form POST with these fields. */
final class Checkout
{
    /** @param array<string,string> $fields */
    public function __construct(
        public readonly string $providerOrderId,
        public readonly string $method,
        public readonly string $url,
        public readonly array $fields = [],
    ) {
    }
}
