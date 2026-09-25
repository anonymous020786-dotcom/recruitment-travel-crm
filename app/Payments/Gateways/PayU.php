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
 * PayU India hosted checkout. The customer's browser POSTs a hash-signed form to PayU; PayU answers by POSTing the result (also
 * server-to-server) with a reverse hash. Both hashes are SHA-512 over pipe-separated fields with the merchant salt:
 *
 *   request  : key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||SALT
 *   response : SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key
 *              (with `additionalCharges|` in front when PayU sends that field)
 *
 * Our own reference is the `txnid` (PayU allows 25 characters). The response is trusted only when its hash verifies.
 */
final class PayU extends AbstractGateway
{
    public function key(): string
    {
        return 'payu';
    }

    public function currencies(): array
    {
        return ['INR'];
    }

    public function createCheckout(CheckoutRequest $r): Checkout
    {
        $key = $this->need('merchant_key');
        $salt = $this->need('merchant_salt');
        $first = mb_substr(preg_replace('/[^A-Za-z0-9 ]+/', '', explode(' ', trim($r->customerName))[0] ?? '') ?: 'Customer', 0, 60);
        $email = $r->customerEmail ?: 'noreply@' . (parse_url($r->callbackUrl, PHP_URL_HOST) ?: 'example.com');
        $productinfo = mb_substr(preg_replace('/[^A-Za-z0-9 .,_-]+/', ' ', $r->description) ?? 'Invoice payment', 0, 100);
        $fields = [
            'key' => $key, 'txnid' => $r->reference, 'amount' => $r->amount(), 'productinfo' => $productinfo, 'firstname' => $first, 'email' => $email,
            'phone' => preg_replace('/\D+/', '', (string) $r->customerPhone) ?: '',
            'surl' => $r->returnUrl, 'furl' => $r->returnUrl . (str_contains($r->returnUrl, '?') ? '&' : '?') . 'cancelled=1',
            'udf1' => '', 'udf2' => '', 'udf3' => '', 'udf4' => '', 'udf5' => '',
        ];
        $fields['hash'] = self::requestHash($fields, $salt);

        return new Checkout($r->reference, 'post', $this->sandbox() ? 'https://test.payu.in/_payment' : 'https://secure.payu.in/_payment', $fields);
    }

    /** @param array<string,string> $f */
    public static function requestHash(array $f, string $salt): string
    {
        return hash('sha512', implode('|', [$f['key'], $f['txnid'], $f['amount'], $f['productinfo'], $f['firstname'], $f['email'], $f['udf1'] ?? '', $f['udf2'] ?? '', $f['udf3'] ?? '', $f['udf4'] ?? '', $f['udf5'] ?? '', '', '', '', '', '', $salt]));
    }

    /** @param array<string,mixed> $f the fields PayU posted */
    public static function responseHash(array $f, string $salt, string $key): string
    {
        $parts = [$salt, (string) ($f['status'] ?? ''), '', '', '', '', '', (string) ($f['udf5'] ?? ''), (string) ($f['udf4'] ?? ''), (string) ($f['udf3'] ?? ''), (string) ($f['udf2'] ?? ''), (string) ($f['udf1'] ?? ''),
            (string) ($f['email'] ?? ''), (string) ($f['firstname'] ?? ''), (string) ($f['productinfo'] ?? ''), (string) ($f['amount'] ?? ''), (string) ($f['txnid'] ?? ''), $key];
        if (isset($f['additionalCharges']) && $f['additionalCharges'] !== '') {
            array_unshift($parts, (string) $f['additionalCharges']);
        }

        return hash('sha512', implode('|', $parts));
    }

    public function parseWebhook(string $rawBody, array $headers, array $post): ?PaymentEvent
    {
        if ($post === [] && $rawBody !== '') {
            parse_str($rawBody, $post);
        }

        return $this->event($post);
    }

    public function handleReturn(array $query, array $post): ?PaymentEvent
    {
        $fields = $post !== [] ? $post : $query;

        return isset($fields['hash']) ? $this->event($fields) : null;
    }

    /** @param array<string,mixed> $f @throws InvalidSignature */
    private function event(array $f): ?PaymentEvent
    {
        if (!isset($f['txnid'], $f['status'])) {
            return null;
        }
        $expected = self::responseHash($f, $this->need('merchant_salt'), $this->need('merchant_key'));
        if (!self::same($expected, strtolower((string) ($f['hash'] ?? '')))) {
            throw new InvalidSignature('PayU response hash does not match.');
        }
        $status = strtolower((string) $f['status']);
        $ref = (string) $f['txnid'];

        return match ($status) {
            'success' => new PaymentEvent(PaymentEvent::PAID, $ref, $ref, (string) ($f['mihpayid'] ?? ''), Money::toMinor((string) ($f['amount'] ?? '0')), 'INR', self::method((string) ($f['mode'] ?? ''))),
            'failure', 'failed', 'usercancelled', 'dropped', 'bounced' => new PaymentEvent(PaymentEvent::FAILED, $ref, $ref, null, null, null, 'other', mb_substr((string) ($f['error_Message'] ?? $f['field9'] ?? 'The payment did not go through.'), 0, 200)),
            'pending' => new PaymentEvent(PaymentEvent::PENDING, $ref, $ref, null, null, null),
            default => null,
        };
    }

    private static function method(string $mode): string
    {
        return match (strtoupper($mode)) { 'UPI' => 'upi', 'CC', 'DC', 'CARD' => 'card', 'NB' => 'bank_transfer', default => 'other' };
    }

    public function test(): array
    {
        try {
            $key = $this->need('merchant_key');
            $salt = $this->need('merchant_salt');
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $var1 = 'GPCONNECTIONTEST00001';
        $r = $this->http->postForm(($this->sandbox() ? 'https://test.payu.in' : 'https://info.payu.in') . '/merchant/postservice?form=2', [
            'key' => $key, 'command' => 'verify_payment', 'var1' => $var1, 'hash' => hash('sha512', "{$key}|verify_payment|{$var1}|{$salt}"),
        ]);
        if ($r['status'] === 0) {
            return ['ok' => false, 'message' => 'PayU could not be reached.'];
        }
        $msg = strtolower((string) ($r['json']['msg'] ?? $r['body']));
        if (preg_match('/invalid (key|hash|merchant)|authentication|unauthori[sz]ed/', $msg) === 1) {
            return ['ok' => false, 'message' => 'PayU rejected the merchant key or salt.'];
        }

        return ['ok' => true, 'message' => 'PayU answered and did not reject the credentials. A sandbox payment is the real proof — try one before going live.'];
    }
}
