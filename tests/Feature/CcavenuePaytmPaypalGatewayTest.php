<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payments\CheckoutRequest;
use App\Payments\GatewayException;
use App\Payments\Gateways\CCAvenue;
use App\Payments\Gateways\Paytm;
use App\Payments\InvalidSignature;
use App\Payments\PaymentEvent;
use Tests\Support\GatewayTestCase;

/** CCAvenue (AES + integrity tag), Paytm (checksum) and PayPal (server-side capture, webhook verification). No live API. */
final class CcavenuePaytmPaypalGatewayTest extends GatewayTestCase
{
    private const REF = 'GPABCDEF0123456789AB';
    private const CC_KEY = '0123456789ABCDEF0123456789ABCDEF';
    private const PAYTM_KEY = 'kbzk1DSbJiV_O3p5';

    private function request(string $currency = 'INR', int $minor = 250075): CheckoutRequest
    {
        return new CheckoutRequest(self::REF, $minor, $currency, 'Invoice INV-7 — Horizon', 'Ravi Kumar', 'ravi@example.test', '9876543210', 'https://crm.example.test/pay/01ABC/return', 'https://crm.example.test/webhooks/x');
    }

    // ---- CCAvenue --------------------------------------------------------------------------------------------------------

    private function ccavenue(string $mode = 'test'): void
    {
        $this->configure('ccavenue', ['merchant_id' => '12345', 'access_code' => 'AVAB01CD23EF45GH67', 'working_key' => self::CC_KEY, 'mode' => $mode]);
    }

    /** Independent implementation of CCAvenue's documented scheme, so the adapter is checked against the spec and not against itself. */
    private function ccEncrypt(string $plain, string $key = self::CC_KEY): string
    {
        return bin2hex((string) openssl_encrypt($plain, 'aes-128-cbc', md5($key, true), OPENSSL_RAW_DATA, pack('C*', ...range(0, 15))));
    }

    private function ccDecrypt(string $hex, string $key = self::CC_KEY): string
    {
        return (string) openssl_decrypt((string) hex2bin($hex), 'aes-128-cbc', md5($key, true), OPENSSL_RAW_DATA, pack('C*', ...range(0, 15)));
    }

    public function test_ccavenue_encrypts_the_order_with_a_tamper_evident_tag_and_posts_to_the_right_host(): void
    {
        $this->ccavenue('test');

        $c = $this->gateway('ccavenue')->createCheckout($this->request());

        self::assertSame(['post', 'https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction'], [$c->method, $c->url]);
        self::assertSame(['encRequest', 'access_code'], array_keys($c->fields));
        self::assertSame('AVAB01CD23EF45GH67', $c->fields['access_code']);
        parse_str($this->ccDecrypt($c->fields['encRequest']), $order);
        self::assertSame(['12345', self::REF, 'INR', '2500.75'], [$order['merchant_id'], $order['order_id'], $order['currency'], $order['amount']]);
        self::assertSame('https://crm.example.test/pay/01ABC/return', $order['redirect_url']);
        self::assertSame(substr(hash_hmac('sha256', self::REF . '|2500.75|INR', self::CC_KEY), 0, 32), $order['merchant_param1'], 'the integrity tag CCAvenue will echo back');
        self::assertStringNotContainsString(self::CC_KEY, json_encode($c->fields), 'the working key never leaves the server');
    }

    public function test_ccavenue_live_host(): void
    {
        $this->ccavenue('live');

        self::assertStringStartsWith('https://secure.ccavenue.com/', $this->gateway('ccavenue')->createCheckout($this->request())->url);
    }

    /** @return array{encResp:string} a response as CCAvenue would post it */
    private function ccResponse(array $over = [], string $key = self::CC_KEY): array
    {
        $d = $over + ['order_id' => self::REF, 'tracking_id' => '114000001234', 'order_status' => 'Success', 'amount' => '2500.75', 'currency' => 'INR', 'payment_mode' => 'Net Banking'];
        $d['merchant_param1'] ??= substr(hash_hmac('sha256', "{$d['order_id']}|{$d['amount']}|{$d['currency']}", $key), 0, 32);

        return ['encResp' => $this->ccEncrypt(http_build_query($d), $key)];
    }

    public function test_a_valid_ccavenue_response_becomes_a_paid_event(): void
    {
        $this->ccavenue();

        $e = $this->gateway('ccavenue')->handleReturn([], $this->ccResponse());

        self::assertSame(PaymentEvent::PAID, $e->kind);
        self::assertSame([self::REF, '114000001234', 250075, 'INR', 'bank_transfer'], [$e->reference, $e->providerPaymentId, $e->amountMinor, $e->currency, $e->method]);
        self::assertSame(PaymentEvent::PAID, $this->gateway('ccavenue')->parseWebhook(http_build_query($this->ccResponse()), [], [])->kind);
    }

    public function test_ccavenue_responses_that_cannot_be_authenticated_are_refused(): void
    {
        $this->ccavenue();
        $gw = $this->gateway('ccavenue');

        foreach ([
            'encrypted with another key' => $this->ccResponse([], 'ffffffffffffffffffffffffffffffff'),
            'right key, forged tag' => $this->ccResponse(['merchant_param1' => str_repeat('a', 32)]),
            'right key, no tag' => ['encResp' => $this->ccEncrypt(http_build_query(['order_id' => self::REF, 'amount' => '1.00', 'currency' => 'INR', 'order_status' => 'Success']))],
            'amount changed under a valid tag' => ['encResp' => $this->ccEncrypt(http_build_query(['order_id' => self::REF, 'amount' => '1.00', 'currency' => 'INR', 'order_status' => 'Success', 'merchant_param1' => substr(hash_hmac('sha256', self::REF . '|2500.75|INR', self::CC_KEY), 0, 32)]))],
            'not hex' => ['encResp' => 'zzzz'],
            'not block-aligned' => ['encResp' => 'abcdef'],
        ] as $label => $post) {
            try {
                $gw->handleReturn([], $post);
                self::fail("accepted: {$label}");
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
        $flipped = $this->ccResponse()['encResp'];
        $flipped[40] = $flipped[40] === 'a' ? 'b' : 'a';   // a single flipped ciphertext bit (CBC is malleable): the tag catches it
        try {
            $gw->handleReturn([], ['encResp' => $flipped]);
            self::fail('accepted a bit-flipped response');
        } catch (InvalidSignature) {
            self::assertTrue(true);
        }
        self::assertNull($gw->handleReturn([], []), 'a visit with no response is just a page view');
    }

    public function test_ccavenue_failures_and_payment_modes(): void
    {
        $this->ccavenue();
        $gw = $this->gateway('ccavenue');

        foreach (['Failure' => PaymentEvent::FAILED, 'Aborted' => PaymentEvent::FAILED, 'Timeout' => PaymentEvent::FAILED] as $status => $kind) {
            self::assertSame($kind, $gw->handleReturn([], $this->ccResponse(['order_status' => $status, 'failure_message' => 'Declined']))->kind, $status);
        }
        self::assertNull($gw->handleReturn([], $this->ccResponse(['order_status' => 'Something else'])));
        foreach (['UPI' => 'upi', 'Credit Card' => 'card', 'Debit Card' => 'card', 'Net Banking' => 'bank_transfer', 'Wallet' => 'other'] as $mode => $ours) {
            self::assertSame($ours, $gw->handleReturn([], $this->ccResponse(['payment_mode' => $mode]))->method, $mode);
        }
    }

    public function test_ccavenue_connection_check_validates_what_it_can(): void
    {
        $this->ccavenue();
        self::assertTrue($this->gateway('ccavenue')->test()['ok']);
        $this->configure('ccavenue', ['working_key' => 'too-short']);
        $r = $this->gateway('ccavenue')->test();
        self::assertFalse($r['ok']);
        self::assertStringContainsString('32 characters', $r['message']);
    }

    // ---- Paytm -------------------------------------------------------------------------------------------------------------------

    private function paytm(string $mode = 'test'): void
    {
        $this->configure('paytm', ['merchant_id' => 'MERCHANT123', 'merchant_key' => self::PAYTM_KEY, 'website' => 'WEBSTAGING', 'mode' => $mode]);
    }

    /** Paytm's documented checksum, written independently here. */
    private function ptSign(string $string, string $salt = 'AbC1'): string
    {
        return (string) openssl_encrypt(hash('sha256', $string . '|' . $salt) . $salt, 'AES-128-CBC', self::PAYTM_KEY, 0, '@@@@&&&&####$$$$');
    }

    public function test_the_paytm_checksum_round_trips_and_detects_any_change(): void
    {
        $sig = Paytm::sign('{"a":1}', self::PAYTM_KEY, 'Zz9k');

        self::assertSame($this->ptSign('{"a":1}', 'Zz9k'), $sig, 'matches the documented scheme byte for byte');
        self::assertTrue(Paytm::verify('{"a":1}', $sig, self::PAYTM_KEY));
        self::assertFalse(Paytm::verify('{"a":2}', $sig, self::PAYTM_KEY), 'a changed message');
        self::assertFalse(Paytm::verify('{"a":1}', $sig, 'another-16-chars!'), 'a different key');
        self::assertFalse(Paytm::verify('{"a":1}', '', self::PAYTM_KEY));
        self::assertFalse(Paytm::verify('{"a":1}', 'not base64 at all!', self::PAYTM_KEY));
        self::assertNotSame(Paytm::sign('x', self::PAYTM_KEY), Paytm::sign('x', self::PAYTM_KEY), 'a fresh random salt each time (both still verify)');
        self::assertSame('a|b||d', Paytm::paramString(['d' => 'd', 'b' => 'b', 'a' => 'a', 'c' => 'null']), 'sorted by name, "null" and null become empty');
    }

    public function test_paytm_initiates_a_signed_transaction_and_returns_a_form_post_with_the_token(): void
    {
        $this->paytm('test');
        $this->http->push(['status' => 200, 'json' => ['body' => ['resultInfo' => ['resultStatus' => 'S'], 'txnToken' => 'tok-123']]]);

        $c = $this->gateway('paytm')->createCheckout($this->request());

        $call = $this->http->last();
        $sent = $this->http->lastBody();
        self::assertSame('https://securegw-stage.paytm.in/theia/api/v1/initiateTransaction?mid=MERCHANT123&orderId=' . self::REF, $call['url']);
        self::assertSame(['Payment', 'MERCHANT123', 'WEBSTAGING', self::REF, '2500.75', 'INR'], [$sent['body']['requestType'], $sent['body']['mid'], $sent['body']['websiteName'], $sent['body']['orderId'], $sent['body']['txnAmount']['value'], $sent['body']['txnAmount']['currency']]);
        self::assertTrue(Paytm::verify((string) json_encode($sent['body'], JSON_UNESCAPED_SLASHES), $sent['head']['signature'], self::PAYTM_KEY), 'the request checksum verifies against the exact JSON sent');
        self::assertSame(['post', 'https://securegw-stage.paytm.in/theia/api/v1/showPaymentPage?mid=MERCHANT123&orderId=' . self::REF, ['mid' => 'MERCHANT123', 'orderId' => self::REF, 'txnToken' => 'tok-123']], [$c->method, $c->url, $c->fields]);
    }

    public function test_paytm_refusals_are_errors_without_secrets(): void
    {
        $this->paytm();
        $this->http->push(['status' => 200, 'json' => ['body' => ['resultInfo' => ['resultStatus' => 'F', 'resultMsg' => 'Invalid checksum']]]]);
        try {
            $this->gateway('paytm')->createCheckout($this->request());
            self::fail('accepted a failure');
        } catch (GatewayException $e) {
            self::assertStringContainsString('Invalid checksum', $e->getMessage());
            self::assertStringNotContainsString(self::PAYTM_KEY, $e->getMessage());
        }
    }

    /** @return array<string,string> a Paytm callback with a valid CHECKSUMHASH */
    private function paytmCallback(array $over = []): array
    {
        $p = $over + ['MID' => 'MERCHANT123', 'ORDERID' => self::REF, 'TXNID' => '20260925111212800110168', 'TXNAMOUNT' => '2500.75', 'CURRENCY' => 'INR', 'STATUS' => 'TXN_SUCCESS', 'RESPCODE' => '01', 'RESPMSG' => 'Txn Success', 'PAYMENTMODE' => 'UPI'];
        $p['CHECKSUMHASH'] ??= $this->ptSign(Paytm::paramString($p));

        return $p;
    }

    public function test_a_checksum_verified_paytm_callback_becomes_a_paid_event(): void
    {
        $this->paytm();
        $p = $this->paytmCallback();

        $ret = $this->gateway('paytm')->handleReturn([], $p);
        $hook = $this->gateway('paytm')->parseWebhook(http_build_query($p), [], []);

        foreach ([$ret, $hook] as $e) {
            self::assertSame(PaymentEvent::PAID, $e->kind);
            self::assertSame([self::REF, '20260925111212800110168', 250075, 'INR', 'upi'], [$e->reference, $e->providerPaymentId, $e->amountMinor, $e->currency, $e->method]);
        }
    }

    public function test_paytm_forged_or_altered_callbacks_are_refused_and_statuses_map(): void
    {
        $this->paytm();
        $gw = $this->gateway('paytm');

        $good = $this->paytmCallback();
        foreach ([
            'amount altered' => ['TXNAMOUNT' => '1.00'] + $good,
            'status flipped' => ['STATUS' => 'TXN_SUCCESS'] + $this->paytmCallback(['STATUS' => 'TXN_FAILURE']),
            'no checksum' => ['CHECKSUMHASH' => ''] + $good,
            'a field added' => $good + ['EXTRA' => 'x'],
        ] as $label => $post) {
            try {
                $gw->handleReturn([], $post);
                self::fail("accepted: {$label}");
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
        self::assertNull($gw->handleReturn([], ['some' => 'post']));
        self::assertSame(PaymentEvent::FAILED, $gw->handleReturn([], $this->paytmCallback(['STATUS' => 'TXN_FAILURE']))->kind);
        self::assertSame(PaymentEvent::PENDING, $gw->handleReturn([], $this->paytmCallback(['STATUS' => 'PENDING']))->kind);
        foreach (['UPI' => 'upi', 'CC' => 'card', 'DC' => 'card', 'NB' => 'bank_transfer', 'PPI' => 'other'] as $mode => $ours) {
            self::assertSame($ours, $gw->handleReturn([], $this->paytmCallback(['PAYMENTMODE' => $mode]))->method, $mode);
        }
    }

    // ---- PayPal ---------------------------------------------------------------------------------------------------------------------

    private function paypal(string $mode = 'test'): void
    {
        $this->configure('paypal', ['client_id' => 'AeA1client', 'client_secret' => 'EC-secret-abcdefghij', 'webhook_id' => 'WH-1234', 'mode' => $mode]);
    }

    private function tokenResponse(): array
    {
        return ['status' => 200, 'json' => ['access_token' => 'A21AAtoken', 'expires_in' => 32400]];
    }

    public function test_paypal_signs_in_once_and_creates_an_order_with_our_reference_as_custom_id(): void
    {
        $this->paypal('test');
        $this->http->push($this->tokenResponse())->push(['status' => 201, 'json' => ['id' => 'ORDER12345678', 'links' => [['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/x'], ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345678']]]]);

        $c = $this->gateway('paypal')->createCheckout($this->request('USD', 12345));

        [$token, $order] = $this->http->calls;
        self::assertSame('https://api-m.sandbox.paypal.com/v1/oauth2/token', $token['url']);
        self::assertSame('Basic ' . base64_encode('AeA1client:EC-secret-abcdefghij'), $token['headers']['Authorization']);
        self::assertSame('https://api-m.sandbox.paypal.com/v2/checkout/orders', $order['url']);
        self::assertSame('Bearer A21AAtoken', $order['headers']['Authorization']);
        $b = json_decode((string) $order['body'], true);
        self::assertSame(['CAPTURE', self::REF, ['currency_code' => 'USD', 'value' => '123.45']], [$b['intent'], $b['purchase_units'][0]['custom_id'], $b['purchase_units'][0]['amount']]);
        self::assertSame('https://crm.example.test/pay/01ABC/return', $b['payment_source']['paypal']['experience_context']['return_url']);
        self::assertSame(['ORDER12345678', 'redirect', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345678'], [$c->providerOrderId, $c->method, $c->url]);

        $this->http->push(['status' => 201, 'json' => ['id' => 'ORDER2', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/x']]]]);
        $this->gateway('paypal')->createCheckout($this->request('USD', 100));
        self::assertCount(3, $this->http->calls, 'the token was reused, not fetched again');
    }

    public function test_paypal_cannot_collect_rupees_and_a_non_https_approval_url_is_refused(): void
    {
        $this->paypal();
        self::assertNotContains('INR', $this->gateway('paypal')->currencies());
        self::assertContains('USD', $this->gateway('paypal')->currencies());

        $this->http->push($this->tokenResponse())->push(['status' => 201, 'json' => ['id' => 'O', 'links' => [['rel' => 'approve', 'href' => 'http://evil.example']]]]);
        $this->expectException(GatewayException::class);
        $this->gateway('paypal')->createCheckout($this->request('USD'));
    }

    private function captureBody(string $status = 'COMPLETED'): array
    {
        return ['status' => 200, 'json' => ['id' => 'ORDER12345678', 'status' => 'COMPLETED', 'purchase_units' => [['custom_id' => self::REF, 'payments' => ['captures' => [
            ['id' => '3C679366HH908993F', 'status' => $status, 'amount' => ['currency_code' => 'USD', 'value' => '123.45'], 'custom_id' => self::REF],
        ]]]]]];
    }

    public function test_when_the_customer_returns_we_capture_the_order_ourselves_and_report_the_capture(): void
    {
        $this->paypal();
        $this->http->push($this->tokenResponse())->push($this->captureBody());

        $e = $this->gateway('paypal')->handleReturn(['token' => 'ORDER12345678', 'PayerID' => 'X'], []);

        $capture = $this->http->last();
        self::assertSame('POST', $capture['method']);
        self::assertSame('https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER12345678/capture', $capture['url']);
        self::assertSame([PaymentEvent::PAID, self::REF, '3C679366HH908993F', 12345, 'USD'], [$e->kind, $e->reference, $e->providerPaymentId, $e->amountMinor, $e->currency], 'amount and reference come from PayPal, not from the browser');
    }

    public function test_an_already_captured_order_is_read_instead_and_bad_or_unapproved_returns_do_nothing(): void
    {
        $this->paypal();
        $this->http->push($this->tokenResponse())->push(['status' => 422, 'json' => ['details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]]])->push($this->captureBody());
        $e = $this->gateway('paypal')->handleReturn(['token' => 'ORDER12345678'], []);
        self::assertSame(PaymentEvent::PAID, $e->kind);
        self::assertSame('GET', $this->http->last()['method']);

        self::assertNull($this->gateway('paypal')->handleReturn(['token' => '../../x'], []), 'a malformed token is never put in a URL');
        self::assertNull($this->gateway('paypal')->handleReturn([], []));
        $this->http->push(['status' => 422, 'json' => ['details' => [['issue' => 'ORDER_NOT_APPROVED']]]]);
        self::assertNull($this->gateway('paypal')->handleReturn(['token' => 'ORDER12345678', 'cancelled' => '1'], []), 'cancelled at PayPal: nothing to capture');
    }

    public function test_paypal_declined_and_pending_captures_map_to_events(): void
    {
        $this->paypal();
        $this->http->push($this->tokenResponse())->push($this->captureBody('DECLINED'))->push($this->captureBody('PENDING'));

        self::assertSame(PaymentEvent::FAILED, $this->gateway('paypal')->handleReturn(['token' => 'ORDER12345678'], [])->kind);
        self::assertSame(PaymentEvent::PENDING, $this->gateway('paypal')->handleReturn(['token' => 'ORDER12345678'], [])->kind);
    }

    private function ppHeaders(): array
    {
        return ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.paypal.com/v1/notifications/certs/CERT-1', 'paypal-transmission-id' => 'tx-1', 'paypal-transmission-sig' => 'c2ln', 'paypal-transmission-time' => '2026-09-25T10:00:00Z'];
    }

    public function test_a_paypal_webhook_is_accepted_only_when_paypal_confirms_it_and_then_maps_to_a_paid_event(): void
    {
        $this->paypal();
        $body = (string) json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => '3C679366HH908993F', 'custom_id' => self::REF, 'amount' => ['currency_code' => 'USD', 'value' => '123.45'], 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER12345678']]]]);
        $this->http->push($this->tokenResponse())->push(['status' => 200, 'json' => ['verification_status' => 'SUCCESS']]);

        $e = $this->gateway('paypal')->parseWebhook($body, $this->ppHeaders(), []);

        $verify = $this->http->last();
        self::assertSame('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature', $verify['url']);
        $sent = $this->http->lastBody();
        self::assertSame(['WH-1234', 'tx-1', 'SHA256withRSA', 'https://api.paypal.com/v1/notifications/certs/CERT-1'], [$sent['webhook_id'], $sent['transmission_id'], $sent['auth_algo'], $sent['cert_url']]);
        self::assertSame('PAYMENT.CAPTURE.COMPLETED', $sent['webhook_event']['event_type']);
        self::assertSame([PaymentEvent::PAID, self::REF, 'ORDER12345678', '3C679366HH908993F', 12345], [$e->kind, $e->reference, $e->providerOrderId, $e->providerPaymentId, $e->amountMinor], 'the same capture id the return path reports, so one payment is recorded once');
    }

    public function test_an_unconfirmed_paypal_webhook_is_refused_and_other_events_are_ignored(): void
    {
        $this->paypal();
        $body = (string) json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'X', 'custom_id' => self::REF]]);
        $this->http->push($this->tokenResponse());   // the OAuth token is fetched once and reused
        foreach ([['status' => 200, 'json' => ['verification_status' => 'FAILURE']], ['status' => 401, 'json' => []], ['status' => 200, 'json' => []]] as $answer) {
            $this->http->push($answer);
            try {
                $this->gateway('paypal')->parseWebhook($body, $this->ppHeaders(), []);
                self::fail('accepted an unconfirmed webhook');
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
        $other = (string) json_encode(['event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => []]);
        $this->http->push(['status' => 200, 'json' => ['verification_status' => 'SUCCESS']]);
        self::assertNull($this->gateway('paypal')->parseWebhook($other, $this->ppHeaders(), []));
        $denied = (string) json_encode(['event_type' => 'PAYMENT.CAPTURE.DENIED', 'resource' => ['custom_id' => self::REF]]);
        $this->http->push(['status' => 200, 'json' => ['verification_status' => 'SUCCESS']]);
        self::assertSame(PaymentEvent::FAILED, $this->gateway('paypal')->parseWebhook($denied, $this->ppHeaders(), [])->kind);
    }

    public function test_paypal_connection_test_and_the_full_lineup_of_eight_gateways_is_registered(): void
    {
        $this->paypal();
        $this->http->push($this->tokenResponse());
        self::assertTrue($this->gateway('paypal')->test()['ok']);
        $this->http->push(['status' => 401, 'json' => ['error' => 'invalid_client']]);
        $fresh = new \App\Payments\Gateways\PayPal($this->app->get(\App\Integrations\Credentials::class), $this->http);
        self::assertFalse($fresh->test()['ok']);

        self::assertSame(['razorpay', 'payu', 'cashfree', 'phonepe', 'ccavenue', 'paytm', 'paypal', 'stripe'], array_keys(\App\Payments\GatewayRegistry::ADAPTERS), 'six Indian gateways plus PayPal and Stripe');
        $registry = $this->app->get(\App\Integrations\Credentials::class);
        foreach (array_keys(\App\Payments\GatewayRegistry::ADAPTERS) as $key) {
            self::assertNotNull($registry->service($key), "{$key} has a card under Admin → Integrations");
        }
    }
}
