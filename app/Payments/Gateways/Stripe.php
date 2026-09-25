<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Payments\AbstractGateway;
use App\Payments\Checkout;
use App\Payments\CheckoutRequest;
use App\Payments\GatewayException;
use App\Payments\InvalidSignature;
use App\Payments\PaymentEvent;

/**
 * Stripe Checkout (hosted page). We create a Checkout Session and send the customer to its URL; Stripe tells us the outcome through a
 * signed webhook (`Stripe-Signature: t=…,v1=…`, HMAC-SHA256 of "t.body" with the endpoint's signing secret, five-minute tolerance).
 * Two-decimal currencies only (zero- and three-decimal ones need different amount handling and are not offered).
 */
final class Stripe extends AbstractGateway
{
    public const TOLERANCE = 300;
    private const API = 'https://api.stripe.com/v1';

    public function key(): string
    {
        return 'stripe';
    }

    public function currencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'INR', 'AED', 'SAR', 'AUD', 'CAD', 'SGD', 'CHF', 'SEK', 'NOK', 'DKK', 'NZD', 'MYR', 'HKD', 'QAR'];
    }

    public function createCheckout(CheckoutRequest $r): Checkout
    {
        $response = $this->http->postForm(self::API . '/checkout/sessions', array_filter([
            'mode' => 'payment',
            'success_url' => $r->returnUrl . (str_contains($r->returnUrl, '?') ? '&' : '?') . 'paid=1',
            'cancel_url' => $r->returnUrl . (str_contains($r->returnUrl, '?') ? '&' : '?') . 'cancelled=1',
            'client_reference_id' => $r->reference,
            'customer_email' => $r->customerEmail,
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => strtolower($r->currency),
            'line_items[0][price_data][unit_amount]' => (string) $r->amountMinor,
            'line_items[0][price_data][product_data][name]' => mb_substr($r->description, 0, 250),
            'payment_intent_data[metadata][reference]' => $r->reference,
        ], static fn ($v): bool => $v !== null && $v !== ''), ['Authorization' => 'Bearer ' . $this->need('secret_key')]);

        $url = (string) ($response['json']['url'] ?? '');
        if (!$response['ok'] || $url === '' || !str_starts_with($url, 'https://')) {
            $this->fail($response, 'start a checkout');
        }

        return new Checkout((string) $response['json']['id'], 'redirect', $url);
    }

    public function parseWebhook(string $rawBody, array $headers, array $post): ?PaymentEvent
    {
        $this->verify($rawBody, (string) ($headers['stripe-signature'] ?? ''));
        $event = self::decode($rawBody);
        $type = (string) ($event['type'] ?? '');
        $o = (array) ($event['data']['object'] ?? []);
        if (!str_starts_with($type, 'checkout.session.') || $o === []) {
            return null;
        }
        $reference = isset($o['client_reference_id']) ? (string) $o['client_reference_id'] : null;
        $session = (string) ($o['id'] ?? '');

        return match (true) {
            in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && ($o['payment_status'] ?? '') === 'paid'
                => new PaymentEvent(PaymentEvent::PAID, $reference, $session, (string) ($o['payment_intent'] ?? $session), (int) ($o['amount_total'] ?? 0), strtoupper((string) ($o['currency'] ?? '')), 'card'),
            $type === 'checkout.session.completed' => new PaymentEvent(PaymentEvent::PENDING, $reference, $session, null, null, null),   // completed but not yet paid (a delayed method)
            in_array($type, ['checkout.session.async_payment_failed', 'checkout.session.expired'], true)
                => new PaymentEvent(PaymentEvent::FAILED, $reference, $session, null, null, null, 'card', $type === 'checkout.session.expired' ? 'The checkout expired.' : 'The payment failed.'),
            default => null,
        };
    }

    /** @throws InvalidSignature */
    private function verify(string $raw, string $header): void
    {
        $timestamp = 0;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp <= 0 || $signatures === [] || abs($this->now() - $timestamp) > self::TOLERANCE) {
            throw new InvalidSignature('Stripe signature missing, malformed or outside the time tolerance.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $this->need('webhook_secret'));
        foreach ($signatures as $s) {
            if (self::same($expected, $s)) {
                return;
            }
        }

        throw new InvalidSignature('Stripe signature does not match.');
    }

    public function test(): array
    {
        try {
            $r = $this->http->get(self::API . '/balance', ['Authorization' => 'Bearer ' . $this->need('secret_key')]);
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return match (true) {
            $r['ok'] => ['ok' => true, 'message' => 'Connected to Stripe (' . (($r['json']['livemode'] ?? false) ? 'live' : 'test') . ' mode).'],
            $r['status'] === 401 => ['ok' => false, 'message' => 'Stripe rejected the secret key (401).'],
            $r['status'] === 403 => ['ok' => true, 'message' => 'The key is valid but restricted (it cannot read the balance) — that is fine for taking payments.'],
            default => ['ok' => false, 'message' => 'Stripe could not be reached or answered HTTP ' . $r['status'] . '.'],
        };
    }
}
