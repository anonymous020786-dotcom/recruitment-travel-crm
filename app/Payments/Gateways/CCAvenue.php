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
 * CCAvenue hosted checkout. The order is sent as an AES-128-CBC encrypted, urlencoded string (`encRequest`, key = md5(working key),
 * fixed IV 0x00..0x0F, hex encoded) and the result comes back the same way (`encResp`).
 *
 * AES-CBC alone does not authenticate a message — an attacker who cannot read it could still flip bits in it. So the encrypted request
 * carries `merchant_param1`, an HMAC-SHA256 (keyed with the working key) over `order_id|amount|currency`, which CCAvenue echoes back;
 * a response is accepted only when it decrypts to a well-formed order AND that HMAC matches the order it claims to be about.
 * CCAvenue only reports through the customer's browser return, so a customer who closes the tab before returning is not recorded
 * automatically — staff see the link still "pending" and can check the CCAvenue dashboard.
 */
final class CCAvenue extends AbstractGateway
{
    public function key(): string
    {
        return 'ccavenue';
    }

    public function currencies(): array
    {
        return ['INR'];
    }

    private function aesKey(): string
    {
        return md5($this->need('working_key'), true);
    }

    private static function iv(): string
    {
        return pack('C*', 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15);
    }

    public function encrypt(string $plain): string
    {
        $c = openssl_encrypt($plain, 'aes-128-cbc', $this->aesKey(), OPENSSL_RAW_DATA, self::iv());

        return $c === false ? throw new GatewayException('CCAvenue request could not be encrypted.') : bin2hex($c);
    }

    public function decrypt(string $hex): ?string
    {
        $raw = preg_match('/^(?:[0-9a-f]{2})+$/Di', $hex) === 1 ? hex2bin($hex) : false;
        if ($raw === false || $raw === '' || strlen($raw) % 16 !== 0) {
            return null;
        }
        $plain = openssl_decrypt($raw, 'aes-128-cbc', $this->aesKey(), OPENSSL_RAW_DATA, self::iv());

        return $plain === false ? null : $plain;
    }

    /** The integrity tag carried inside the encrypted order. */
    private function tag(string $orderId, string $amount, string $currency): string
    {
        return substr(hash_hmac('sha256', "{$orderId}|{$amount}|{$currency}", $this->need('working_key')), 0, 32);
    }

    public function createCheckout(CheckoutRequest $r): Checkout
    {
        $amount = $r->amount();
        $plain = http_build_query(array_filter([
            'merchant_id' => $this->need('merchant_id'), 'order_id' => $r->reference, 'currency' => 'INR', 'amount' => $amount,
            'redirect_url' => $r->returnUrl, 'cancel_url' => $r->returnUrl . (str_contains($r->returnUrl, '?') ? '&' : '?') . 'cancelled=1', 'language' => 'EN',
            'billing_name' => mb_substr($r->customerName, 0, 60), 'billing_tel' => preg_replace('/\D+/', '', (string) $r->customerPhone) ?: null, 'billing_email' => $r->customerEmail,
            'merchant_param1' => $this->tag($r->reference, $amount, 'INR'),
        ], static fn ($v): bool => $v !== null && $v !== ''));

        return new Checkout($r->reference, 'post',
            ($this->sandbox() ? 'https://test.ccavenue.com' : 'https://secure.ccavenue.com') . '/transaction/transaction.do?command=initiateTransaction',
            ['encRequest' => $this->encrypt($plain), 'access_code' => $this->need('access_code')]);
    }

    public function parseWebhook(string $rawBody, array $headers, array $post): ?PaymentEvent
    {
        return $this->handleReturn([], $post !== [] ? $post : (static function (string $raw): array { parse_str($raw, $p); return $p; })($rawBody));
    }

    public function handleReturn(array $query, array $post): ?PaymentEvent
    {
        $enc = (string) ($post['encResp'] ?? '');
        if ($enc === '') {
            return null;
        }
        $plain = $this->decrypt($enc);
        if ($plain === null) {
            throw new InvalidSignature('CCAvenue response could not be decrypted with this working key.');
        }
        parse_str($plain, $d);
        $order = (string) ($d['order_id'] ?? '');
        $amount = (string) ($d['amount'] ?? '');
        $currency = strtoupper((string) ($d['currency'] ?? ''));
        if ($order === '' || $amount === '' || !self::same($this->tag($order, $amount, $currency), (string) ($d['merchant_param1'] ?? ''))) {
            throw new InvalidSignature('CCAvenue response failed its integrity check.');
        }

        return match (strtolower((string) ($d['order_status'] ?? ''))) {
            'success' => new PaymentEvent(PaymentEvent::PAID, $order, $order, (string) ($d['tracking_id'] ?? ''), Money::toMinor($amount), $currency, self::method((string) ($d['payment_mode'] ?? ''))),
            'failure', 'aborted', 'invalid', 'timeout' => new PaymentEvent(PaymentEvent::FAILED, $order, $order, null, null, null, 'other', mb_substr((string) ($d['failure_message'] ?? $d['status_message'] ?? 'The payment did not go through.'), 0, 200)),
            default => null,
        };
    }

    private static function method(string $mode): string
    {
        return match (strtolower($mode)) { 'upi' => 'upi', 'credit card', 'debit card', 'card' => 'card', 'net banking' => 'bank_transfer', default => 'other' };
    }

    public function test(): array
    {
        try {
            $this->need('merchant_id');
            $this->need('access_code');
            $wk = $this->need('working_key');
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if (preg_match('/^[0-9A-Fa-f]{32}$/D', $wk) !== 1) {
            return ['ok' => false, 'message' => 'The working key is normally 32 characters (letters A–F and digits). Check it against the CCAvenue dashboard.'];
        }

        return ['ok' => true, 'message' => 'The credentials are complete and well-formed. CCAvenue has no cheap connection check — make one test payment in test mode to confirm them.'];
    }
}
