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
 * Paytm Payment Gateway. Requests carry Paytm's checksum in `head.signature`; the customer is then sent to Paytm's payment page
 * with the transaction token (a form POST). The result comes back as a form POST (to the callback URL and as a server-to-server
 * notification) with a `CHECKSUMHASH`.
 *
 * Paytm's checksum: string = the JSON body (requests) or the sorted parameter VALUES joined by "|" (responses); salt = 4 random
 * characters; signature = base64(AES-128-CBC(sha256(string|salt) . salt, merchant key, iv "@@@@&&&&####$$$$")). Verification decrypts,
 * recomputes with the salt found at the end, and compares in constant time.
 */
final class Paytm extends AbstractGateway
{
    private const IV = '@@@@&&&&####$$$$';

    public function key(): string
    {
        return 'paytm';
    }

    public function currencies(): array
    {
        return ['INR'];
    }

    private function host(): string
    {
        return $this->sandbox() ? 'https://securegw-stage.paytm.in' : 'https://securegw.paytm.in';
    }

    // ---- checksum -----------------------------------------------------------------------------------------------------

    public static function sign(string $string, string $merchantKey, ?string $salt = null): string
    {
        $salt ??= substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 4);
        $cipher = openssl_encrypt(hash('sha256', $string . '|' . $salt) . $salt, 'aes-128-cbc', $merchantKey, 0, self::IV);

        return $cipher === false ? throw new GatewayException('Paytm checksum could not be generated (is the merchant key 16 characters?).') : $cipher;
    }

    public static function verify(string $string, string $checksum, string $merchantKey): bool
    {
        $plain = $checksum === '' ? false : @openssl_decrypt($checksum, 'aes-128-cbc', $merchantKey, 0, self::IV);
        if ($plain === false || strlen($plain) < 68) {
            return false;
        }
        $salt = substr($plain, -4);

        return hash_equals(hash('sha256', $string . '|' . $salt) . $salt, $plain);
    }

    /** @param array<string,mixed> $params */
    public static function paramString(array $params): string
    {
        ksort($params);

        return implode('|', array_map(static fn ($v): string => ($v === null || strtolower((string) $v) === 'null') ? '' : (string) $v, $params));
    }

    // ---- gateway ------------------------------------------------------------------------------------------------------

    public function createCheckout(CheckoutRequest $r): Checkout
    {
        $mid = $this->need('merchant_id');
        $key = $this->need('merchant_key');
        $body = [
            'requestType' => 'Payment', 'mid' => $mid, 'websiteName' => $this->need('website'), 'orderId' => $r->reference, 'callbackUrl' => $r->returnUrl,
            'txnAmount' => ['value' => $r->amount(), 'currency' => 'INR'], 'userInfo' => ['custId' => 'C' . substr($r->reference, 2, 18)],
        ];
        $signature = self::sign((string) json_encode($body, JSON_UNESCAPED_SLASHES), $key);
        $url = $this->host() . '/theia/api/v1/initiateTransaction?' . http_build_query(['mid' => $mid, 'orderId' => $r->reference]);
        $response = $this->http->postJson($url, ['body' => $body, 'head' => ['signature' => $signature]]);

        $token = (string) ($response['json']['body']['txnToken'] ?? '');
        if (!$response['ok'] || ($response['json']['body']['resultInfo']['resultStatus'] ?? '') !== 'S' || $token === '') {
            $message = (string) ($response['json']['body']['resultInfo']['resultMsg'] ?? '');
            throw new GatewayException('Paytm did not accept the request to start a payment' . ($message !== '' ? ': ' . mb_substr($message, 0, 160) : ' (HTTP ' . $response['status'] . ')') . '.');
        }

        return new Checkout($r->reference, 'post', $this->host() . '/theia/api/v1/showPaymentPage?' . http_build_query(['mid' => $mid, 'orderId' => $r->reference]),
            ['mid' => $mid, 'orderId' => $r->reference, 'txnToken' => $token]);
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
        return isset($post['CHECKSUMHASH']) ? $this->event($post) : null;
    }

    /** @param array<string,mixed> $p @throws InvalidSignature */
    private function event(array $p): ?PaymentEvent
    {
        $checksum = (string) ($p['CHECKSUMHASH'] ?? '');
        unset($p['CHECKSUMHASH']);
        if (!isset($p['ORDERID'], $p['STATUS'])) {
            return null;
        }
        if (!self::verify(self::paramString($p), $checksum, $this->need('merchant_key'))) {
            throw new InvalidSignature('Paytm checksum does not verify.');
        }
        $order = (string) $p['ORDERID'];

        return match (strtoupper((string) $p['STATUS'])) {
            'TXN_SUCCESS' => new PaymentEvent(PaymentEvent::PAID, $order, $order, (string) ($p['TXNID'] ?? ''), Money::toMinor((string) ($p['TXNAMOUNT'] ?? '0')), strtoupper((string) ($p['CURRENCY'] ?? 'INR')),
                match (strtoupper((string) ($p['PAYMENTMODE'] ?? ''))) { 'UPI' => 'upi', 'CC', 'DC' => 'card', 'NB' => 'bank_transfer', default => 'other' }),
            'TXN_FAILURE' => new PaymentEvent(PaymentEvent::FAILED, $order, $order, null, null, null, 'other', mb_substr((string) ($p['RESPMSG'] ?? 'The payment did not go through.'), 0, 200)),
            'PENDING' => new PaymentEvent(PaymentEvent::PENDING, $order, $order, null, null, null),
            default => null,
        };
    }

    public function test(): array
    {
        try {
            $mid = $this->need('merchant_id');
            $body = ['mid' => $mid, 'orderId' => 'GPCONNECTIONTEST00001'];
            $r = $this->http->postJson($this->host() . '/v3/order/status', ['body' => $body, 'head' => ['signature' => self::sign((string) json_encode($body, JSON_UNESCAPED_SLASHES), $this->need('merchant_key'))]]);
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($r['status'] === 0) {
            return ['ok' => false, 'message' => 'Paytm could not be reached.'];
        }
        $code = (string) ($r['json']['body']['resultInfo']['resultCode'] ?? '');

        return match (true) {
            in_array($code, ['330', '227'], true) => ['ok' => false, 'message' => 'Paytm rejected the merchant id / key (checksum error ' . $code . ').'],
            default => ['ok' => true, 'message' => 'Paytm answered (' . ($code !== '' ? 'result ' . $code : 'HTTP ' . $r['status']) . ') and did not reject the credentials. A staging payment is the real proof — try one before going live.'],
        };
    }
}
