<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Payments\AbstractGateway;
use App\Payments\Checkout;
use App\Payments\CheckoutRequest;
use App\Payments\GatewayException;
use App\Payments\InvalidSignature;
use App\Payments\PaymentEvent;
use App\Support\Money;

/**
 * Cashfree Payment Links: a link for the exact amount, our reference as the link id, the customer redirected to its URL. Cashfree
 * reports back with a signed webhook: `x-webhook-signature` = base64(HMAC-SHA256(x-webhook-timestamp . rawBody, secret key)).
 *
 * Only the payment-LINK paid event settles an invoice (the link id is our reference, and a link is paid at most once). The separate
 * per-payment event is deliberately ignored so one payment can never be seen — and recorded — under two different ids.
 * Enable "Payment Link" events for the webhook in the Cashfree dashboard.
 */
final class Cashfree extends AbstractGateway
{
    public const TOLERANCE = 600;

    public function key(): string
    {
        return 'cashfree';
    }

    public function currencies(): array
    {
        return ['INR'];
    }

    private function base(): string
    {
        return $this->sandbox() ? 'https://sandbox.cashfree.com/pg' : 'https://api.cashfree.com/pg';
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        return ['x-client-id' => $this->need('app_id'), 'x-client-secret' => $this->need('secret_key'), 'x-api-version' => '2023-08-01'];
    }

    public function createCheckout(CheckoutRequest $r): Checkout
    {
        $phone = preg_replace('/\D+/', '', (string) $r->customerPhone) ?? '';
        $phone = strlen($phone) > 10 ? substr($phone, -10) : $phone;
        if (strlen($phone) !== 10) {
            throw new GatewayException('Cashfree needs the customer\'s 10-digit mobile number on the invoice.');
        }
        $body = [
            'link_id' => $r->reference, 'link_amount' => (float) $r->amount(), 'link_currency' => 'INR', 'link_purpose' => mb_substr($r->description, 0, 500),
            'customer_details' => array_filter(['customer_name' => $r->customerName, 'customer_phone' => $phone, 'customer_email' => $r->customerEmail], static fn ($v): bool => $v !== null && $v !== ''),
            'link_partial_payments' => false, 'link_notify' => ['send_sms' => false, 'send_email' => false],
            'link_meta' => ['return_url' => $r->returnUrl, 'notify_url' => $r->callbackUrl],
            'link_expiry_time' => gmdate('Y-m-d\TH:i:s\Z', $this->now() + 7 * 86400),
        ];
        $response = $this->http->postJson($this->base() . '/links', $body, $this->auth());
        $url = (string) ($response['json']['link_url'] ?? '');
        if (!$response['ok'] || $url === '' || !str_starts_with($url, 'https://')) {
            $this->fail($response, 'create a payment link');
        }

        return new Checkout((string) ($response['json']['link_id'] ?? $r->reference), 'redirect', $url);
    }

    public function parseWebhook(string $rawBody, array $headers, array $post): ?PaymentEvent
    {
        $timestamp = (string) ($headers['x-webhook-timestamp'] ?? '');
        $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $this->need('secret_key'), true));
        if ($timestamp === '' || !self::same($expected, (string) ($headers['x-webhook-signature'] ?? ''))) {
            throw new InvalidSignature('Cashfree webhook signature does not match.');
        }
        // Cashfree timestamps are in milliseconds (or seconds); reject replays of old deliveries either way.
        $ts = (int) $timestamp > 100000000000 ? intdiv((int) $timestamp, 1000) : (int) $timestamp;
        if (abs($this->now() - $ts) > self::TOLERANCE) {
            throw new InvalidSignature('Cashfree webhook timestamp is outside the tolerance.');
        }

        $event = self::decode($rawBody);
        if (($event['type'] ?? '') !== 'PAYMENT_LINK_EVENT') {
            return null;
        }
        $d = (array) ($event['data'] ?? []);
        $link = (string) ($d['link_id'] ?? '');
        $status = strtoupper((string) ($d['link_status'] ?? ''));

        return match (true) {
            $link === '' => null,
            $status === 'PAID' => new PaymentEvent(PaymentEvent::PAID, $link, $link, 'link:' . $link, Money::toMinor((string) ($d['link_amount_paid'] ?? $d['link_amount'] ?? '0')), strtoupper((string) ($d['link_currency'] ?? 'INR')), 'other'),
            in_array($status, ['EXPIRED', 'CANCELLED'], true) => new PaymentEvent(PaymentEvent::FAILED, $link, $link, null, null, null, 'other', 'The payment link ' . strtolower($status) . '.'),
            default => null,
        };
    }

    public function test(): array
    {
        try {
            $r = $this->http->get($this->base() . '/orders/GPCONNECTIONTEST00001', $this->auth());
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return match (true) {
            in_array($r['status'], [200, 404, 400], true) => ['ok' => true, 'message' => 'Cashfree accepted the app id and secret (' . ($this->sandbox() ? 'sandbox' : 'live') . ').'],
            in_array($r['status'], [401, 403], true) => ['ok' => false, 'message' => 'Cashfree rejected the app id / secret key (HTTP ' . $r['status'] . ') — check the mode too: sandbox keys only work in test mode.'],
            default => ['ok' => false, 'message' => 'Cashfree could not be reached or answered HTTP ' . $r['status'] . '.'],
        };
    }
}
