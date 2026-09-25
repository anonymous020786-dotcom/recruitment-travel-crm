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
 * PayPal Orders v2. We create an order (intent CAPTURE, our reference as `custom_id`) and send the customer to PayPal's approval
 * page. When they come back we CAPTURE the order ourselves, server-to-server with our own OAuth token — so the browser's word is never
 * needed. PayPal's `PAYMENT.CAPTURE.COMPLETED` webhook is verified by asking PayPal (`verify-webhook-signature`, PayPal fetches its own
 * certificate — we never fetch a URL from a request). Both routes report the same capture id, so a payment seen by both is recorded once.
 */
final class PayPal extends AbstractGateway
{
    /** @var array{token:string,until:int}|null */
    private ?array $token = null;

    public function key(): string
    {
        return 'paypal';
    }

    public function currencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'CHF', 'SEK', 'NOK', 'DKK', 'NZD', 'HKD', 'MYR'];
    }

    private function base(): string
    {
        return $this->sandbox() ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    /** @return array<string,string> */
    private function bearer(): array
    {
        if ($this->token === null || $this->token['until'] <= $this->now()) {
            $r = $this->http->postForm($this->base() . '/v1/oauth2/token', ['grant_type' => 'client_credentials'], ['Authorization' => 'Basic ' . base64_encode($this->need('client_id') . ':' . $this->need('client_secret'))]);
            $t = (string) ($r['json']['access_token'] ?? '');
            if (!$r['ok'] || $t === '') {
                $this->fail($r, 'sign in');
            }
            $this->token = ['token' => $t, 'until' => $this->now() + max(60, (int) ($r['json']['expires_in'] ?? 300) - 60)];
        }

        return ['Authorization' => 'Bearer ' . $this->token['token']];
    }

    public function createCheckout(CheckoutRequest $r): Checkout
    {
        $response = $this->http->postJson($this->base() . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $r->reference, 'custom_id' => $r->reference, 'description' => mb_substr($r->description, 0, 127),
                'amount' => ['currency_code' => strtoupper($r->currency), 'value' => $r->amount()],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $r->returnUrl, 'cancel_url' => $r->returnUrl . (str_contains($r->returnUrl, '?') ? '&' : '?') . 'cancelled=1',
                'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING',
            ]]],
        ], $this->bearer() + ['PayPal-Request-Id' => $r->reference . '-' . bin2hex(random_bytes(4))]);

        $url = '';
        foreach ((array) ($response['json']['links'] ?? []) as $l) {
            if (in_array($l['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $url = (string) ($l['href'] ?? '');
                break;
            }
        }
        if (!$response['ok'] || $url === '' || !str_starts_with($url, 'https://') || empty($response['json']['id'])) {
            $this->fail($response, 'create an order');
        }

        return new Checkout((string) $response['json']['id'], 'redirect', $url);
    }

    /** The customer approved and came back (`?token=<order id>`): capture the order and report the result. */
    public function handleReturn(array $query, array $post): ?PaymentEvent
    {
        $order = (string) ($query['token'] ?? '');
        if (preg_match('/^[A-Z0-9]{10,30}$/D', $order) !== 1) {
            return null;
        }
        $auth = $this->bearer();
        $r = $this->http->postJson($this->base() . "/v2/checkout/orders/{$order}/capture", [], $auth + ['PayPal-Request-Id' => 'cap-' . $order]);
        if (!$r['ok'] && ($r['json']['details'][0]['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED') {
            $r = $this->http->get($this->base() . "/v2/checkout/orders/{$order}", $auth);   // already captured (webhook or a reload): read it instead
        }
        if (!$r['ok'] || !is_array($r['json'])) {
            return null;
        }
        $unit = (array) ($r['json']['purchase_units'][0] ?? []);
        $capture = (array) ($unit['payments']['captures'][0] ?? []);
        $reference = (string) ($unit['custom_id'] ?? $capture['custom_id'] ?? $unit['reference_id'] ?? '');
        if ($reference === '' || ($capture['id'] ?? '') === '') {
            return null;
        }
        if (($capture['status'] ?? '') !== 'COMPLETED') {
            return in_array($capture['status'] ?? '', ['DECLINED', 'FAILED'], true)
                ? new PaymentEvent(PaymentEvent::FAILED, $reference, $order, null, null, null, 'other', 'PayPal declined the payment.')
                : new PaymentEvent(PaymentEvent::PENDING, $reference, $order, null, null, null);
        }

        return new PaymentEvent(PaymentEvent::PAID, $reference, $order, (string) $capture['id'], Money::toMinor((string) ($capture['amount']['value'] ?? '0')), strtoupper((string) ($capture['amount']['currency_code'] ?? '')), 'other');
    }

    public function parseWebhook(string $rawBody, array $headers, array $post): ?PaymentEvent
    {
        $event = self::decode($rawBody);
        $verify = $this->http->postJson($this->base() . '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => (string) ($headers['paypal-auth-algo'] ?? ''), 'cert_url' => (string) ($headers['paypal-cert-url'] ?? ''),
            'transmission_id' => (string) ($headers['paypal-transmission-id'] ?? ''), 'transmission_sig' => (string) ($headers['paypal-transmission-sig'] ?? ''),
            'transmission_time' => (string) ($headers['paypal-transmission-time'] ?? ''), 'webhook_id' => $this->need('webhook_id'), 'webhook_event' => $event,
        ], $this->bearer());
        if (!$verify['ok'] || ($verify['json']['verification_status'] ?? '') !== 'SUCCESS' || $event === []) {
            throw new InvalidSignature('PayPal did not confirm this webhook.');
        }

        $type = (string) ($event['event_type'] ?? '');
        $res = (array) ($event['resource'] ?? []);
        $reference = isset($res['custom_id']) ? (string) $res['custom_id'] : null;

        return match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => new PaymentEvent(PaymentEvent::PAID, $reference, isset($res['supplementary_data']['related_ids']['order_id']) ? (string) $res['supplementary_data']['related_ids']['order_id'] : null,
                (string) ($res['id'] ?? ''), Money::toMinor((string) ($res['amount']['value'] ?? '0')), strtoupper((string) ($res['amount']['currency_code'] ?? '')), 'other'),
            'PAYMENT.CAPTURE.DENIED' => new PaymentEvent(PaymentEvent::FAILED, $reference, null, null, null, null, 'other', 'PayPal denied the payment.'),
            default => null,
        };
    }

    public function test(): array
    {
        try {
            $this->bearer();
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Signed in to PayPal (' . ($this->sandbox() ? 'sandbox' : 'live') . '). The webhook id is checked when the first webhook arrives.'];
    }
}
