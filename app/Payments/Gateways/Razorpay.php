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
 * Razorpay Payment Links: we create a link for the exact amount and send the customer to its short URL. Razorpay reports the result
 * by a signed webhook (`X-Razorpay-Signature` = hex HMAC-SHA256 of the raw body with the webhook secret) and by redirecting the
 * customer back with a signature over `link_id|reference_id|status|payment_id` made with the key secret.
 */
final class Razorpay extends AbstractGateway
{
    private const API = 'https://api.razorpay.com/v1';

    public function key(): string
    {
        return 'razorpay';
    }

    public function currencies(): array
    {
        return ['INR'];
    }

    private function auth(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode($this->need('key_id') . ':' . $this->need('key_secret'))];
    }

    public function createCheckout(CheckoutRequest $r): Checkout
    {
        $body = [
            'amount' => $r->amountMinor, 'currency' => 'INR', 'accept_partial' => false, 'reference_id' => $r->reference,
            'description' => mb_substr($r->description, 0, 2000), 'reminder_enable' => false,
            'notify' => ['sms' => false, 'email' => false], 'callback_url' => $r->returnUrl, 'callback_method' => 'get',
            'expire_by' => $this->now() + 7 * 86400,
            'customer' => array_filter(['name' => $r->customerName, 'email' => $r->customerEmail, 'contact' => $r->customerPhone], static fn ($v): bool => $v !== null && $v !== ''),
        ];
        $response = $this->http->postJson(self::API . '/payment_links', $body, $this->auth());
        $url = (string) ($response['json']['short_url'] ?? '');
        if (!$response['ok'] || $url === '' || !str_starts_with($url, 'https://')) {
            $this->fail($response, 'create a payment link');
        }

        return new Checkout((string) $response['json']['id'], 'redirect', $url);
    }

    public function parseWebhook(string $rawBody, array $headers, array $post): ?PaymentEvent
    {
        $expected = hash_hmac('sha256', $rawBody, $this->need('webhook_secret'));
        if (!self::same($expected, (string) ($headers['x-razorpay-signature'] ?? ''))) {
            throw new InvalidSignature('Razorpay webhook signature does not match.');
        }
        $event = self::decode($rawBody);
        $type = (string) ($event['event'] ?? '');
        $link = (array) ($event['payload']['payment_link']['entity'] ?? []);
        $payment = (array) ($event['payload']['payment']['entity'] ?? []);

        return match ($type) {
            'payment_link.paid' => new PaymentEvent(
                PaymentEvent::PAID, isset($link['reference_id']) ? (string) $link['reference_id'] : null, (string) ($link['id'] ?? ''),
                (string) ($payment['id'] ?? ''), isset($payment['amount']) ? (int) $payment['amount'] : (isset($link['amount_paid']) ? (int) $link['amount_paid'] : null),
                strtoupper((string) ($payment['currency'] ?? $link['currency'] ?? 'INR')), self::method((string) ($payment['method'] ?? '')),
            ),
            'payment_link.expired', 'payment_link.cancelled' => new PaymentEvent(PaymentEvent::FAILED, isset($link['reference_id']) ? (string) $link['reference_id'] : null, (string) ($link['id'] ?? ''), null, null, null, 'other', 'The payment link ' . substr($type, 13) . '.'),
            default => null,
        };
    }

    public function handleReturn(array $query, array $post): ?PaymentEvent
    {
        $sig = (string) ($query['razorpay_signature'] ?? '');
        if ($sig === '') {
            return null;
        }
        $link = (string) ($query['razorpay_payment_link_id'] ?? '');
        $ref = (string) ($query['razorpay_payment_link_reference_id'] ?? '');
        $status = (string) ($query['razorpay_payment_link_status'] ?? '');
        $pay = (string) ($query['razorpay_payment_id'] ?? '');
        $expected = hash_hmac('sha256', "{$link}|{$ref}|{$status}|{$pay}", $this->need('key_secret'));
        if (!self::same($expected, $sig)) {
            throw new InvalidSignature('Razorpay return signature does not match.');
        }

        return $status === 'paid' ? new PaymentEvent(PaymentEvent::PAID, $ref, $link, $pay, null, 'INR') : null;
    }

    /** Razorpay's payment method names → ours. */
    private static function method(string $m): string
    {
        return match ($m) { 'upi' => 'upi', 'card' => 'card', 'netbanking' => 'bank_transfer', default => 'other' };
    }

    public function test(): array
    {
        try {
            $r = $this->http->get(self::API . '/payments?count=1', $this->auth());
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return match (true) {
            $r['ok'] => ['ok' => true, 'message' => 'Connected to Razorpay.'],
            $r['status'] === 401 => ['ok' => false, 'message' => 'Razorpay rejected the key id / key secret (401).'],
            default => ['ok' => false, 'message' => 'Razorpay could not be reached or answered HTTP ' . $r['status'] . '.'],
        };
    }
}
