<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payments\CheckoutRequest;
use App\Payments\GatewayException;
use App\Payments\InvalidSignature;
use App\Payments\PaymentEvent;
use Tests\Support\GatewayTestCase;

/** PayU, Cashfree and PhonePe: request signing, response/webhook verification (valid, forged, stale) and event mapping. No live API. */
final class PayuCashfreePhonepeGatewayTest extends GatewayTestCase
{
    private const REF = 'GPABCDEF0123456789AB';
    private const PAYU_KEY = 'gtKFFx';
    private const PAYU_SALT = 'eCwWELxi_secret_salt';
    private const CF_SECRET = 'cf_secret_key_abcdefghijklmnop';
    private const PP_SALT = '099eb0cd-02cf-4e2a-8aca-3e6c6aff0399';

    private function request(string $phone = '9876543210', ?string $email = null): CheckoutRequest
    {
        return new CheckoutRequest(self::REF, 250075, 'INR', 'Invoice INV-7 — Horizon & Co', 'Ravi Kumar', $email, $phone, 'https://crm.example.test/pay/01ABC/return', 'https://crm.example.test/webhooks/x');
    }

    // ---- PayU ------------------------------------------------------------------------------------------------------------------

    private function payu(string $mode = 'test'): void
    {
        $this->configure('payu', ['merchant_key' => self::PAYU_KEY, 'merchant_salt' => self::PAYU_SALT, 'mode' => $mode]);
    }

    public function test_payu_builds_a_signed_form_post_for_the_hosted_checkout(): void
    {
        $this->payu('test');

        $c = $this->gateway('payu')->createCheckout($this->request());

        self::assertSame(['post', 'https://test.payu.in/_payment', self::REF], [$c->method, $c->url, $c->providerOrderId]);
        $f = $c->fields;
        self::assertSame([self::PAYU_KEY, self::REF, '2500.75', 'Ravi', '9876543210'], [$f['key'], $f['txnid'], $f['amount'], $f['firstname'], $f['phone']]);
        self::assertStringNotContainsString('&', $f['productinfo'], 'only characters PayU accepts');
        self::assertSame('https://crm.example.test/pay/01ABC/return', $f['surl']);
        self::assertStringEndsWith('cancelled=1', $f['furl']);
        self::assertSame('noreply@crm.example.test', $f['email'], 'PayU insists on an email: a neutral one is supplied');
        $expected = hash('sha512', implode('|', [$f['key'], $f['txnid'], $f['amount'], $f['productinfo'], $f['firstname'], $f['email'], '', '', '', '', '']) . '|||||' . '|' . self::PAYU_SALT);
        self::assertSame($expected, $f['hash'], 'key|txnid|amount|productinfo|firstname|email|udf1..udf5||||||SALT');
        self::assertArrayNotHasKey('merchant_salt', $f);
        self::assertStringNotContainsString(self::PAYU_SALT, json_encode($f), 'the salt never leaves the server');
    }

    public function test_payu_live_mode_posts_to_the_live_host(): void
    {
        $this->payu('live');

        self::assertSame('https://secure.payu.in/_payment', $this->gateway('payu')->createCheckout($this->request())->url);
    }

    /** @return array<string,string> a PayU result with a correct reverse hash */
    private function payuResult(array $over = []): array
    {
        $f = $over + ['status' => 'success', 'txnid' => self::REF, 'amount' => '2500.75', 'productinfo' => 'Invoice INV-7', 'firstname' => 'Ravi', 'email' => 'ravi@example.test',
            'udf1' => '', 'udf2' => '', 'udf3' => '', 'udf4' => '', 'udf5' => '', 'mihpayid' => '403993715520', 'mode' => 'UPI'];
        $f['hash'] ??= hash('sha512', implode('|', [self::PAYU_SALT, $f['status'], '', '', '', '', '', $f['udf5'], $f['udf4'], $f['udf3'], $f['udf2'], $f['udf1'], $f['email'], $f['firstname'], $f['productinfo'], $f['amount'], $f['txnid'], self::PAYU_KEY]));

        return $f;
    }

    public function test_a_hash_verified_payu_success_becomes_a_paid_event_from_the_return_or_the_webhook(): void
    {
        $this->payu();
        $f = $this->payuResult();

        $ret = $this->gateway('payu')->handleReturn([], $f);
        $hook = $this->gateway('payu')->parseWebhook(http_build_query($f), [], []);

        foreach ([$ret, $hook] as $e) {
            self::assertSame(PaymentEvent::PAID, $e->kind);
            self::assertSame([self::REF, '403993715520', 250075, 'INR', 'upi'], [$e->reference, $e->providerPaymentId, $e->amountMinor, $e->currency, $e->method]);
        }
    }

    public function test_payu_forged_or_altered_results_are_refused(): void
    {
        $this->payu();
        $gw = $this->gateway('payu');

        foreach ([
            'amount changed after signing' => $this->payuResult()['hash'] === '' ? [] : ['amount' => '1.00'] + $this->payuResult(),
            'status flipped' => ['status' => 'success'] + $this->payuResult(['status' => 'failure']),
            'txnid swapped' => ['txnid' => 'GPSOMEONEELSE0000001'] + $this->payuResult(),
            'signed with the wrong salt' => $this->payuResult(['hash' => hash('sha512', 'wrong')]),
            'empty hash' => $this->payuResult(['hash' => '']),
        ] as $label => $fields) {
            try {
                $gw->handleReturn([], $fields);
                self::fail("accepted: {$label}");
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
        self::assertNull($gw->handleReturn([], ['some' => 'unrelated post']), 'a post with no hash is not a payment result');
    }

    public function test_payu_additional_charges_change_the_reverse_hash_as_documented_and_statuses_map(): void
    {
        $this->payu();
        $f = $this->payuResult(['additionalCharges' => '12.50', 'hash' => '']);
        $f['hash'] = hash('sha512', implode('|', ['12.50', self::PAYU_SALT, 'success', '', '', '', '', '', '', '', '', '', '', $f['email'], $f['firstname'], $f['productinfo'], $f['amount'], $f['txnid'], self::PAYU_KEY]));
        self::assertSame(PaymentEvent::PAID, $this->gateway('payu')->handleReturn([], $f)->kind);

        foreach (['failure' => PaymentEvent::FAILED, 'pending' => PaymentEvent::PENDING, 'userCancelled' => PaymentEvent::FAILED] as $status => $kind) {
            $r = $this->payuResult(['status' => $status, 'hash' => '']);
            $r['hash'] = hash('sha512', implode('|', [self::PAYU_SALT, $status, '', '', '', '', '', '', '', '', '', '', $r['email'], $r['firstname'], $r['productinfo'], $r['amount'], $r['txnid'], self::PAYU_KEY]));
            self::assertSame($kind, $this->gateway('payu')->handleReturn([], $r)->kind, $status);
        }
        self::assertSame(['UPI' => 'upi', 'CC' => 'card', 'DC' => 'card', 'NB' => 'bank_transfer', 'WALLET' => 'other'], array_map(function (string $mode): string {
            $r = $this->payuResult(['mode' => $mode, 'hash' => '']);
            $r['hash'] = hash('sha512', implode('|', [self::PAYU_SALT, 'success', '', '', '', '', '', '', '', '', '', '', $r['email'], $r['firstname'], $r['productinfo'], $r['amount'], $r['txnid'], self::PAYU_KEY]));

            return $this->gateway('payu')->handleReturn([], $r)->method;
        }, array_combine(['UPI', 'CC', 'DC', 'NB', 'WALLET'], ['UPI', 'CC', 'DC', 'NB', 'WALLET'])));
    }

    // ---- Cashfree -----------------------------------------------------------------------------------------------------------------

    private function cashfree(string $mode = 'test'): void
    {
        $this->configure('cashfree', ['app_id' => 'CF12345', 'secret_key' => self::CF_SECRET, 'mode' => $mode]);
    }

    public function test_cashfree_creates_a_payment_link_with_our_reference_and_the_customers_mobile(): void
    {
        $this->cashfree('test');
        $this->http->push(['status' => 200, 'json' => ['link_id' => self::REF, 'link_url' => 'https://payments-test.cashfree.com/links/abc']]);

        $c = $this->gateway('cashfree')->createCheckout($this->request('+91 98765 43210'));

        $call = $this->http->last();
        $b = $this->http->lastBody();
        self::assertSame('https://sandbox.cashfree.com/pg/links', $call['url']);
        self::assertSame(['CF12345', self::CF_SECRET, '2023-08-01'], [$call['headers']['x-client-id'], $call['headers']['x-client-secret'], $call['headers']['x-api-version']]);
        self::assertSame([self::REF, 2500.75, 'INR', false], [$b['link_id'], $b['link_amount'], $b['link_currency'], $b['link_partial_payments']]);
        self::assertSame('9876543210', $b['customer_details']['customer_phone'], 'the +91 prefix and spaces are dropped');
        self::assertSame(['https://crm.example.test/pay/01ABC/return', 'https://crm.example.test/webhooks/x'], [$b['link_meta']['return_url'], $b['link_meta']['notify_url']]);
        self::assertSame(['redirect', 'https://payments-test.cashfree.com/links/abc'], [$c->method, $c->url]);
    }

    public function test_cashfree_refuses_without_a_mobile_number_and_uses_the_live_host_in_live_mode(): void
    {
        $this->cashfree('live');
        try {
            $this->gateway('cashfree')->createCheckout($this->request(''));
            self::fail('created a link with no phone');
        } catch (GatewayException $e) {
            self::assertStringContainsString('10-digit mobile', $e->getMessage());
        }
        self::assertSame([], $this->http->calls);

        $this->http->push(['status' => 200, 'json' => ['link_id' => 'x', 'link_url' => 'https://payments.cashfree.com/links/x']]);
        $this->gateway('cashfree')->createCheckout($this->request());
        self::assertSame('https://api.cashfree.com/pg/links', $this->http->last()['url']);
    }

    /** @return array{0:string,1:array<string,string>} */
    private function cashfreeHook(array $data, ?int $ts = null, string $type = 'PAYMENT_LINK_EVENT', string $secret = self::CF_SECRET): array
    {
        $body = (string) json_encode(['type' => $type, 'data' => $data]);
        $ts ??= time();

        return [$body, ['x-webhook-timestamp' => (string) $ts, 'x-webhook-signature' => base64_encode(hash_hmac('sha256', $ts . $body, $secret, true))]];
    }

    public function test_a_signed_cashfree_link_paid_event_becomes_a_paid_event(): void
    {
        $this->cashfree();
        [$body, $headers] = $this->cashfreeHook(['link_id' => self::REF, 'link_status' => 'PAID', 'link_amount_paid' => '2500.75', 'link_currency' => 'INR']);

        $e = $this->gateway('cashfree')->parseWebhook($body, $headers, []);

        self::assertSame(PaymentEvent::PAID, $e->kind);
        self::assertSame([self::REF, 'link:' . self::REF, 250075, 'INR'], [$e->reference, $e->providerPaymentId, $e->amountMinor, $e->currency]);
    }

    public function test_cashfree_forged_stale_and_unsigned_deliveries_are_refused_and_millisecond_timestamps_work(): void
    {
        $this->cashfree();
        $gw = $this->gateway('cashfree');
        $data = ['link_id' => self::REF, 'link_status' => 'PAID', 'link_amount_paid' => '1'];

        foreach ([
            'wrong secret' => $this->cashfreeHook($data, null, 'PAYMENT_LINK_EVENT', 'other'),
            'stale' => $this->cashfreeHook($data, time() - 7200),
        ] as $label => [$body, $headers]) {
            try {
                $gw->parseWebhook($body, $headers, []);
                self::fail("accepted {$label}");
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
        [$body, $headers] = $this->cashfreeHook($data);
        foreach ([['x-webhook-signature' => $headers['x-webhook-signature']], ['x-webhook-timestamp' => $headers['x-webhook-timestamp']], []] as $partial) {
            try {
                $gw->parseWebhook($body, $partial, []);
                self::fail('accepted a delivery missing a signature header');
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
        try {
            $gw->parseWebhook($body . ' ', $headers, []);
            self::fail('accepted a changed body');
        } catch (InvalidSignature) {
            self::assertTrue(true);
        }

        [$msBody, $msHeaders] = $this->cashfreeHook($data, time() * 1000);
        self::assertSame(PaymentEvent::PAID, $gw->parseWebhook($msBody, $msHeaders, [])->kind);
    }

    public function test_only_the_link_event_settles_so_one_payment_is_never_seen_under_two_ids(): void
    {
        $this->cashfree();
        $gw = $this->gateway('cashfree');

        [$b1, $h1] = $this->cashfreeHook(['order' => ['order_id' => 'CFPay_x'], 'payment' => ['cf_payment_id' => '99', 'payment_status' => 'SUCCESS']], null, 'PAYMENT_SUCCESS_WEBHOOK');
        self::assertNull($gw->parseWebhook($b1, $h1, []), 'the per-payment event is ignored on purpose');
        [$b2, $h2] = $this->cashfreeHook(['link_id' => self::REF, 'link_status' => 'EXPIRED']);
        self::assertSame(PaymentEvent::FAILED, $gw->parseWebhook($b2, $h2, [])->kind);
        [$b3, $h3] = $this->cashfreeHook(['link_id' => self::REF, 'link_status' => 'PARTIALLY_PAID']);
        self::assertNull($gw->parseWebhook($b3, $h3, []));
    }

    public function test_cashfree_connection_test(): void
    {
        $this->cashfree();
        foreach ([[404, true, 'accepted'], [401, false, 'rejected'], [500, false, 'HTTP 500']] as [$status, $ok, $needle]) {
            $this->http->push(['status' => $status]);
            $r = $this->gateway('cashfree')->test();
            self::assertSame($ok, $r['ok'], (string) $status);
            self::assertStringContainsString($needle, $r['message']);
        }
    }

    // ---- PhonePe ---------------------------------------------------------------------------------------------------------------------

    private function phonepe(string $mode = 'test'): void
    {
        $this->configure('phonepe', ['merchant_id' => 'PGTESTPAYUAT', 'salt_key' => self::PP_SALT, 'salt_index' => '1', 'mode' => $mode]);
    }

    public function test_phonepe_signs_the_pay_request_with_x_verify_and_returns_the_pay_page_url(): void
    {
        $this->phonepe('test');
        $this->http->push(['status' => 200, 'json' => ['success' => true, 'data' => ['instrumentResponse' => ['redirectInfo' => ['url' => 'https://mercury-uat.phonepe.com/transact/abc']]]]]);

        $c = $this->gateway('phonepe')->createCheckout($this->request());

        $call = $this->http->last();
        $sent = $this->http->lastBody();
        self::assertSame('https://api-preprod.phonepe.com/apis/pg-sandbox/pg/v1/pay', $call['url']);
        self::assertSame(hash('sha256', $sent['request'] . '/pg/v1/pay' . self::PP_SALT) . '###1', $call['headers']['X-VERIFY']);
        $payload = json_decode((string) base64_decode($sent['request']), true);
        self::assertSame(['PGTESTPAYUAT', self::REF, 250075, 'PAY_PAGE'], [$payload['merchantId'], $payload['merchantTransactionId'], $payload['amount'], $payload['paymentInstrument']['type']]);
        self::assertSame('https://crm.example.test/webhooks/x', $payload['callbackUrl']);
        self::assertSame(['redirect', 'https://mercury-uat.phonepe.com/transact/abc'], [$c->method, $c->url]);
    }

    public function test_phonepe_rejections_and_a_non_https_pay_url_are_errors(): void
    {
        $this->phonepe();
        $this->http->push(['status' => 400, 'json' => ['success' => false, 'code' => 'BAD_REQUEST', 'message' => 'Invalid X-VERIFY']]);
        try {
            $this->gateway('phonepe')->createCheckout($this->request());
            self::fail('accepted a rejected request');
        } catch (GatewayException $e) {
            self::assertStringNotContainsString(self::PP_SALT, $e->getMessage());
        }
        $this->http->push(['status' => 200, 'json' => ['success' => true, 'data' => ['instrumentResponse' => ['redirectInfo' => ['url' => 'http://insecure.example/x']]]]]);
        $this->expectException(GatewayException::class);
        $this->gateway('phonepe')->createCheckout($this->request());
    }

    /** @return array{0:string,1:array<string,string>} a PhonePe callback (JSON body {"response": base64}) with its X-VERIFY */
    private function phonepeCallback(array $decoded, string $salt = self::PP_SALT): array
    {
        $b64 = base64_encode((string) json_encode($decoded));

        return [(string) json_encode(['response' => $b64]), ['x-verify' => hash('sha256', $b64 . $salt) . '###1']];
    }

    public function test_a_verified_phonepe_callback_becomes_a_paid_event_and_forgeries_are_refused(): void
    {
        $this->phonepe();
        [$body, $headers] = $this->phonepeCallback(['success' => true, 'code' => 'PAYMENT_SUCCESS', 'data' => ['merchantTransactionId' => self::REF, 'transactionId' => 'T2309', 'amount' => 250075, 'paymentInstrument' => ['type' => 'UPI']]]);

        $e = $this->gateway('phonepe')->parseWebhook($body, $headers, []);

        self::assertSame(PaymentEvent::PAID, $e->kind);
        self::assertSame([self::REF, 'T2309', 250075, 'upi'], [$e->reference, $e->providerPaymentId, $e->amountMinor, $e->method]);

        [$forgedBody, $forgedHeaders] = $this->phonepeCallback(['success' => true, 'code' => 'PAYMENT_SUCCESS', 'data' => ['merchantTransactionId' => self::REF]], 'not-the-salt');
        foreach ([[$forgedBody, $forgedHeaders], [$body, []], [$body, ['x-verify' => hash('sha256', 'x') . '###1']], [str_replace('"response":"', '"response":"AA', $body), $headers]] as [$b, $h]) {
            try {
                $this->gateway('phonepe')->parseWebhook($b, $h, []);
                self::fail('accepted a forged callback');
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
    }

    public function test_phonepe_status_codes_map_to_events(): void
    {
        $this->phonepe();
        $gw = $this->gateway('phonepe');
        foreach (['PAYMENT_PENDING' => PaymentEvent::PENDING, 'PAYMENT_ERROR' => PaymentEvent::FAILED, 'PAYMENT_DECLINED' => PaymentEvent::FAILED, 'TIMED_OUT' => PaymentEvent::FAILED] as $code => $kind) {
            [$b, $h] = $this->phonepeCallback(['success' => false, 'code' => $code, 'data' => ['merchantTransactionId' => self::REF]]);
            self::assertSame($kind, $gw->parseWebhook($b, $h, [])->kind, $code);
        }
        [$b, $h] = $this->phonepeCallback(['code' => 'SOMETHING_NEW', 'data' => ['merchantTransactionId' => self::REF]]);
        self::assertNull($gw->parseWebhook($b, $h, []));
    }

    public function test_when_the_customer_returns_phonepe_is_asked_directly_and_the_browsers_word_is_not_taken(): void
    {
        $this->phonepe();
        $this->http->push(['status' => 200, 'json' => ['success' => true, 'code' => 'PAYMENT_SUCCESS', 'data' => ['merchantTransactionId' => self::REF, 'transactionId' => 'T77', 'amount' => 250075, 'paymentInstrument' => ['type' => 'CARD']]]]);

        // the browser POSTs whatever it likes; only the transaction id is used, to ask PhonePe
        $e = $this->gateway('phonepe')->handleReturn([], ['transactionId' => self::REF, 'code' => 'PAYMENT_SUCCESS', 'amount' => '1']);

        $call = $this->http->last();
        $path = '/pg/v1/status/PGTESTPAYUAT/' . self::REF;
        self::assertSame('GET', $call['method']);
        self::assertSame('https://api-preprod.phonepe.com/apis/pg-sandbox' . $path, $call['url']);
        self::assertSame(hash('sha256', $path . self::PP_SALT) . '###1', $call['headers']['X-VERIFY']);
        self::assertSame('PGTESTPAYUAT', $call['headers']['X-MERCHANT-ID']);
        self::assertSame([PaymentEvent::PAID, 'T77', 250075, 'card'], [$e->kind, $e->providerPaymentId, $e->amountMinor, $e->method], 'the amount comes from PhonePe, not from the browser');

        self::assertNull($this->gateway('phonepe')->handleReturn([], ['transactionId' => '../../etc/passwd']), 'a malformed id is never used in a URL');
        $this->http->push(['status' => 500]);
        self::assertNull($this->gateway('phonepe')->handleReturn([], ['transactionId' => self::REF]), 'if PhonePe cannot confirm, nothing is recorded — the callback decides');
    }

    public function test_all_three_only_collect_rupees_and_show_up_as_usable_only_when_configured(): void
    {
        $registry = $this->app->get(\App\Payments\GatewayRegistry::class);
        self::assertSame([], $registry->forCurrency('INR'));

        $this->payu();
        $this->cashfree();
        $this->phonepe();
        $registry = $this->app->get(\App\Payments\GatewayRegistry::class);

        self::assertSame(['payu', 'cashfree', 'phonepe'], array_keys($registry->forCurrency('INR')));
        self::assertSame([], $registry->forCurrency('USD'));
        foreach (['payu', 'cashfree', 'phonepe'] as $k) {
            self::assertSame(['INR'], $registry->usable($k)->currencies());
        }
    }
}
