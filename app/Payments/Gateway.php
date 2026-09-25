<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * One payment gateway. An adapter turns our neutral "collect this amount" request into that gateway's checkout, and turns the
 * gateway's server-to-server (webhook) and browser-return messages back into a neutral PaymentEvent — after verifying the
 * gateway's signature, which is the only thing that makes such a message trustworthy. Nothing else in the application knows how
 * any particular gateway works.
 */
interface Gateway
{
    public function key(): string;

    /** @return list<string> ISO 4217 codes this gateway can collect. Invoices in other currencies cannot be sent to it. */
    public function currencies(): array;

    /** @throws GatewayException when the gateway refuses or cannot be reached */
    public function createCheckout(CheckoutRequest $request): Checkout;

    /**
     * Verify and parse a server-to-server delivery. Returns null for a message that is valid but not about a payment outcome
     * (a "created" notification, a test ping).
     *
     * @param array<string,string> $headers lower-cased names
     * @param array<string,mixed> $post the parsed form body, for gateways that post forms
     * @throws InvalidSignature when the signature (or hash) does not verify — the caller must not act on the message
     */
    public function parseWebhook(string $rawBody, array $headers, array $post): ?PaymentEvent;

    /**
     * The customer's browser coming back to us. Gateways that finish the payment on their own page and post a signed result
     * (PayU, CCAvenue, Paytm) verify it here; gateways that only redirect (Stripe, Razorpay) return null and the webhook decides.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @throws InvalidSignature
     */
    public function handleReturn(array $query, array $post): ?PaymentEvent;

    /** Cheap live check that the saved credentials work. @return array{ok:bool,message:string} */
    public function test(): array;
}
