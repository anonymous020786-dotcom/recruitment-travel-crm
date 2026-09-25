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
 * PhonePe PG (salt-key API). Requests are base64 JSON with `X-VERIFY = sha256(base64 + path + saltKey) ### saltIndex`; the callback
 * (`{"response": base64}`) is verified with `sha256(base64 + saltKey) ### saltIndex`. When the customer's browser returns, the result
 * is not taken from the browser at all: we ask PhonePe for the transaction's status server-to-server (signed the same way) and act on
 * that answer.
 */
final class PhonePe extends AbstractGateway
{
    private const PAY = '/pg/v1/pay';

    public function key(): string
    {
        return 'phonepe';
    }

    public function currencies(): array
    {
        return ['INR'];
    }

    private function base(): string
    {
        return $this->sandbox() ? 'https://api-preprod.phonepe.com/apis/pg-sandbox' : 'https://api.phonepe.com/apis/hermes';
    }

    private function suffix(): string
    {
        return '###' . $this->need('salt_index');
    }

    public function createCheckout(CheckoutRequest $r): Checkout
    {
        $payload = base64_encode((string) json_encode(array_filter([
            'merchantId' => $this->need('merchant_id'), 'merchantTransactionId' => $r->reference, 'merchantUserId' => 'MU' . substr($r->reference, 2, 20),
            'amount' => $r->amountMinor, 'redirectUrl' => $r->returnUrl, 'redirectMode' => 'POST', 'callbackUrl' => $r->callbackUrl,
            'mobileNumber' => preg_replace('/\D+/', '', (string) $r->customerPhone) ?: null, 'paymentInstrument' => ['type' => 'PAY_PAGE'],
        ], static fn ($v): bool => $v !== null), JSON_UNESCAPED_SLASHES));
        $xVerify = hash('sha256', $payload . self::PAY . $this->need('salt_key')) . $this->suffix();

        $response = $this->http->postJson($this->base() . self::PAY, ['request' => $payload], ['X-VERIFY' => $xVerify]);
        $url = (string) ($response['json']['data']['instrumentResponse']['redirectInfo']['url'] ?? '');
        if (!$response['ok'] || ($response['json']['success'] ?? false) !== true || !str_starts_with($url, 'https://')) {
            $this->fail($response, 'start a payment');
        }

        return new Checkout($r->reference, 'redirect', $url);
    }

    public function parseWebhook(string $rawBody, array $headers, array $post): ?PaymentEvent
    {
        $body = self::decode($rawBody);
        $b64 = (string) ($body['response'] ?? ($post['response'] ?? ''));
        $expected = hash('sha256', $b64 . $this->need('salt_key')) . $this->suffix();
        if ($b64 === '' || !self::same($expected, (string) ($headers['x-verify'] ?? ''))) {
            throw new InvalidSignature('PhonePe callback X-VERIFY does not match.');
        }

        return self::toEvent(self::decode((string) base64_decode($b64, true)));
    }

    /** The browser came back: ask PhonePe (signed, server-to-server) what actually happened. */
    public function handleReturn(array $query, array $post): ?PaymentEvent
    {
        $ref = (string) ($post['transactionId'] ?? $post['merchantTransactionId'] ?? $query['transactionId'] ?? '');
        if (preg_match('/^GP[0-9A-F]{18}$/D', $ref) !== 1) {
            return null;
        }
        $mid = $this->need('merchant_id');
        $path = "/pg/v1/status/{$mid}/{$ref}";
        $r = $this->http->get($this->base() . $path, ['X-VERIFY' => hash('sha256', $path . $this->need('salt_key')) . $this->suffix(), 'X-MERCHANT-ID' => $mid]);
        if (!$r['ok'] || !is_array($r['json'])) {
            return null;   // could not confirm: leave it to the callback
        }

        return self::toEvent($r['json']);
    }

    /** @param array<string,mixed> $d PhonePe's decoded response/status body */
    private static function toEvent(array $d): ?PaymentEvent
    {
        $code = (string) ($d['code'] ?? '');
        $data = (array) ($d['data'] ?? []);
        $ref = isset($data['merchantTransactionId']) ? (string) $data['merchantTransactionId'] : null;
        $type = strtoupper((string) ($data['paymentInstrument']['type'] ?? ''));

        return match (true) {
            $ref === null => null,
            $code === 'PAYMENT_SUCCESS' => new PaymentEvent(PaymentEvent::PAID, $ref, $ref, (string) ($data['transactionId'] ?? ''), isset($data['amount']) ? (int) $data['amount'] : null, 'INR',
                match ($type) { 'UPI' => 'upi', 'CARD' => 'card', 'NETBANKING' => 'bank_transfer', default => 'other' }),
            $code === 'PAYMENT_PENDING' => new PaymentEvent(PaymentEvent::PENDING, $ref, $ref, null, null, null),
            in_array($code, ['PAYMENT_ERROR', 'PAYMENT_DECLINED', 'TIMED_OUT', 'PAYMENT_CANCELLED', 'AUTHORIZATION_FAILED'], true)
                => new PaymentEvent(PaymentEvent::FAILED, $ref, $ref, null, null, null, 'other', 'PhonePe: ' . strtolower(str_replace('_', ' ', $code)) . '.'),
            default => null,
        };
    }

    public function test(): array
    {
        try {
            $mid = $this->need('merchant_id');
            $path = "/pg/v1/status/{$mid}/GPCONNECTIONTEST00001";
            $r = $this->http->get($this->base() . $path, ['X-VERIFY' => hash('sha256', $path . $this->need('salt_key')) . $this->suffix(), 'X-MERCHANT-ID' => $mid]);
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($r['status'] === 0) {
            return ['ok' => false, 'message' => 'PhonePe could not be reached.'];
        }
        $code = strtoupper((string) ($r['json']['code'] ?? ''));
        if ($r['status'] === 401 || preg_match('/KEY|VERIFY|CHECKSUM|MERCHANT|UNAUTH/', $code) === 1 && !str_contains($code, 'TRANSACTION')) {
            return ['ok' => false, 'message' => 'PhonePe rejected the merchant id / salt key (' . ($code !== '' ? $code : 'HTTP ' . $r['status']) . ').'];
        }

        return ['ok' => true, 'message' => 'PhonePe answered and did not reject the credentials. A sandbox payment is the real proof — try one before going live.'];
    }
}
