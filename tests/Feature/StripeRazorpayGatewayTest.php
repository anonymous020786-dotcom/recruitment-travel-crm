<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payments\CheckoutRequest;
use App\Payments\GatewayException;
use App\Payments\InvalidSignature;
use App\Payments\PaymentEvent;
use Tests\Support\GatewayTestCase;

/** The Stripe and Razorpay adapters: request shape, signature verification (valid, forged, stale) and event mapping. No live API. */
final class StripeRazorpayGatewayTest extends GatewayTestCase
{
    private const STRIPE_SECRET = 'sk_test_abcdefghijklmnop123456';
    private const WHSEC = 'whsec_test_secret_value_123456';
    private const RZP_SECRET = 'rzp_key_secret_abcdefghijk';
    private const RZP_WEBHOOK = 'rzp_webhook_secret_12345';

    private function request(string $currency = 'INR', int $minor = 1234500): CheckoutRequest
    {
        return new CheckoutRequest('GPABCDEF0123456789AB', $minor, $currency, 'Invoice INV-1 — Horizon', 'Ravi Kumar', 'ravi@example.test', '9876543210',
            'https://crm.example.test/pay/01ABC/return', 'https://crm.example.test/webhooks/stripe');
    }

    private function stripe(): void
    {
        $this->configure('stripe', ['publishable_key' => 'pk_test_1', 'secret_key' => self::STRIPE_SECRET, 'webhook_secret' => self::WHSEC]);
    }

    private function razorpay(): void
    {
        $this->configure('razorpay', ['key_id' => 'rzp_test_ABC123', 'key_secret' => self::RZP_SECRET, 'webhook_secret' => self::RZP_WEBHOOK, 'mode' => 'test']);
    }

    private function stripeHeader(string $body, ?int $t = null, string $secret = self::WHSEC): string
    {
        $t ??= time();

        return "t={$t},v1=" . hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    // ---- Stripe -----------------------------------------------------------------------------------------------------------

    public function test_stripe_creates_a_hosted_checkout_session_for_the_exact_amount(): void
    {
        $this->stripe();
        $this->http->push(['status' => 200, 'json' => ['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123']]);

        $checkout = $this->gateway('stripe')->createCheckout($this->request('USD', 12345));

        $call = $this->http->last();
        $body = $this->http->lastBody();
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $call['url']);
        self::assertSame('Bearer ' . self::STRIPE_SECRET, $call['headers']['Authorization']);
        self::assertSame('payment', $body['mode']);
        self::assertSame('GPABCDEF0123456789AB', $body['client_reference_id']);
        self::assertSame('12345', $body['line_items'][0]['price_data']['unit_amount'], 'the amount is sent in minor units');
        self::assertSame('usd', $body['line_items'][0]['price_data']['currency']);
        self::assertSame('GPABCDEF0123456789AB', $body['payment_intent_data']['metadata']['reference']);
        self::assertSame('https://crm.example.test/pay/01ABC/return?paid=1', $body['success_url']);
        self::assertSame('https://crm.example.test/pay/01ABC/return?cancelled=1', $body['cancel_url']);
        self::assertSame(['cs_test_123', 'redirect', 'https://checkout.stripe.com/c/pay/cs_test_123'], [$checkout->providerOrderId, $checkout->method, $checkout->url]);
    }

    public function test_a_stripe_error_is_reported_without_leaking_the_key_and_a_non_https_url_is_refused(): void
    {
        $this->stripe();
        $this->http->push(['status' => 401, 'json' => ['error' => ['message' => 'Invalid API Key provided']]]);
        try {
            $this->gateway('stripe')->createCheckout($this->request('USD'));
            self::fail('accepted a rejected request');
        } catch (GatewayException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringNotContainsString(self::STRIPE_SECRET, $e->getMessage());
        }

        $this->http->push(['status' => 200, 'json' => ['id' => 'cs_x', 'url' => 'http://evil.example/steal']]);
        $this->expectException(GatewayException::class);
        $this->gateway('stripe')->createCheckout($this->request('USD'));
    }

    public function test_a_signed_stripe_payment_is_parsed_into_a_paid_event(): void
    {
        $this->stripe();
        $body = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_test_1', 'payment_status' => 'paid', 'payment_intent' => 'pi_123', 'amount_total' => 12345, 'currency' => 'usd', 'client_reference_id' => 'GPABCDEF0123456789AB',
        ]]]);

        $e = $this->gateway('stripe')->parseWebhook($body, ['stripe-signature' => $this->stripeHeader($body)], []);

        self::assertSame(PaymentEvent::PAID, $e->kind);
        self::assertSame(['GPABCDEF0123456789AB', 'cs_test_1', 'pi_123', 12345, 'USD', 'card'], [$e->reference, $e->providerOrderId, $e->providerPaymentId, $e->amountMinor, $e->currency, $e->method]);
    }

    public function test_stripe_forged_stale_and_malformed_signatures_are_all_refused(): void
    {
        $this->stripe();
        $body = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['payment_status' => 'paid']]]);
        $gw = $this->gateway('stripe');

        foreach ([
            'wrong secret' => $this->stripeHeader($body, null, 'whsec_someone_elses'),
            'tampered body' => $this->stripeHeader($body . ' '),
            'stale (replay)' => $this->stripeHeader($body, time() - 3600),
            'from the future' => $this->stripeHeader($body, time() + 3600),
            'no header' => '',
            'no v1' => 't=' . time(),
            'garbage' => 'not a signature',
        ] as $label => $header) {
            try {
                $gw->parseWebhook($body, ['stripe-signature' => $header], []);
                self::fail("accepted a {$label} signature");
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
        // a header carrying several v1 values is fine as long as one of them is right (key rotation)
        $t = time();
        $ok = "t={$t},v1=" . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::WHSEC);
        self::assertSame(PaymentEvent::PAID, $gw->parseWebhook($body, ['stripe-signature' => $ok], [])->kind);
    }

    public function test_stripe_event_types_map_to_paid_pending_failed_or_nothing(): void
    {
        $this->stripe();
        $gw = $this->gateway('stripe');
        $parse = function (string $type, array $object) use ($gw): ?PaymentEvent {
            $body = json_encode(['type' => $type, 'data' => ['object' => $object + ['id' => 'cs_1', 'client_reference_id' => 'GPX']]]);

            return $gw->parseWebhook($body, ['stripe-signature' => $this->stripeHeader($body)], []);
        };

        self::assertSame(PaymentEvent::PENDING, $parse('checkout.session.completed', ['payment_status' => 'unpaid'])->kind, 'completed but not yet paid (a delayed method)');
        self::assertSame(PaymentEvent::PAID, $parse('checkout.session.async_payment_succeeded', ['payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'inr'])->kind);
        self::assertSame(PaymentEvent::FAILED, $parse('checkout.session.async_payment_failed', [])->kind);
        self::assertSame(PaymentEvent::FAILED, $parse('checkout.session.expired', [])->kind);
        self::assertNull($parse('customer.created', ['id' => 'cus_1']), 'unrelated events are ignored');
    }

    public function test_stripe_connection_test_explains_each_outcome(): void
    {
        $this->stripe();
        foreach ([[200, ['livemode' => false], true, 'test mode'], [401, [], false, 'rejected'], [403, [], true, 'restricted'], [500, [], false, 'HTTP 500']] as [$status, $json, $ok, $needle]) {
            $this->http->push(['status' => $status, 'json' => $json]);
            $r = $this->gateway('stripe')->test();
            self::assertSame($ok, $r['ok'], (string) $status);
            self::assertStringContainsString($needle, $r['message']);
        }
    }

    // ---- Razorpay -----------------------------------------------------------------------------------------------------------

    public function test_razorpay_creates_a_payment_link_in_paise_with_basic_auth(): void
    {
        $this->razorpay();
        $this->http->push(['status' => 200, 'json' => ['id' => 'plink_ABC', 'short_url' => 'https://rzp.io/i/abc123']]);

        $c = $this->gateway('razorpay')->createCheckout($this->request());

        $call = $this->http->last();
        $b = $this->http->lastBody();
        self::assertSame('https://api.razorpay.com/v1/payment_links', $call['url']);
        self::assertSame('Basic ' . base64_encode('rzp_test_ABC123:' . self::RZP_SECRET), $call['headers']['Authorization']);
        self::assertSame([1234500, 'INR', 'GPABCDEF0123456789AB', false], [$b['amount'], $b['currency'], $b['reference_id'], $b['accept_partial']]);
        self::assertSame('https://crm.example.test/pay/01ABC/return', $b['callback_url']);
        self::assertSame(['name' => 'Ravi Kumar', 'email' => 'ravi@example.test', 'contact' => '9876543210'], $b['customer']);
        self::assertGreaterThan(time() + 6 * 86400, $b['expire_by']);
        self::assertSame(['plink_ABC', 'redirect', 'https://rzp.io/i/abc123'], [$c->providerOrderId, $c->method, $c->url]);
    }

    public function test_razorpay_only_collects_rupees(): void
    {
        $this->razorpay();

        self::assertSame(['INR'], $this->gateway('razorpay')->currencies());
    }

    private function rzpBody(array $overrides = []): string
    {
        return (string) json_encode(array_replace_recursive(['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => 'plink_ABC', 'reference_id' => 'GPABCDEF0123456789AB', 'amount' => 1234500, 'currency' => 'INR']],
            'payment' => ['entity' => ['id' => 'pay_XYZ', 'amount' => 1234500, 'currency' => 'INR', 'method' => 'upi']],
        ]], $overrides));
    }

    public function test_a_signed_razorpay_webhook_becomes_a_paid_event_and_a_forged_one_is_refused(): void
    {
        $this->razorpay();
        $body = $this->rzpBody();

        $e = $this->gateway('razorpay')->parseWebhook($body, ['x-razorpay-signature' => hash_hmac('sha256', $body, self::RZP_WEBHOOK)], []);

        self::assertSame(PaymentEvent::PAID, $e->kind);
        self::assertSame(['GPABCDEF0123456789AB', 'plink_ABC', 'pay_XYZ', 1234500, 'INR', 'upi'], [$e->reference, $e->providerOrderId, $e->providerPaymentId, $e->amountMinor, $e->currency, $e->method]);

        foreach (['wrong key' => hash_hmac('sha256', $body, 'other'), 'tampered' => hash_hmac('sha256', $body . 'x', self::RZP_WEBHOOK), 'empty' => '', 'uppercase hex is not what Razorpay sends' => strtoupper(hash_hmac('sha256', $body, self::RZP_WEBHOOK))] as $label => $sig) {
            try {
                $this->gateway('razorpay')->parseWebhook($body, ['x-razorpay-signature' => $sig], []);
                self::fail("accepted {$label}");
            } catch (InvalidSignature) {
                self::assertTrue(true);
            }
        }
    }

    public function test_razorpay_methods_map_to_ours_and_expired_links_become_failures(): void
    {
        $this->razorpay();
        $gw = $this->gateway('razorpay');
        $sign = static fn (string $b): array => ['x-razorpay-signature' => hash_hmac('sha256', $b, self::RZP_WEBHOOK)];

        foreach (['upi' => 'upi', 'card' => 'card', 'netbanking' => 'bank_transfer', 'wallet' => 'other', 'emi' => 'other'] as $rzp => $ours) {
            $b = $this->rzpBody(['payload' => ['payment' => ['entity' => ['method' => $rzp]]]]);
            self::assertSame($ours, $gw->parseWebhook($b, $sign($b), [])->method, $rzp);
        }
        $b = (string) json_encode(['event' => 'payment_link.expired', 'payload' => ['payment_link' => ['entity' => ['id' => 'plink_ABC', 'reference_id' => 'GPX']]]]);
        self::assertSame(PaymentEvent::FAILED, $gw->parseWebhook($b, $sign($b), [])->kind);
        $b = (string) json_encode(['event' => 'payment.authorized', 'payload' => []]);
        self::assertNull($gw->parseWebhook($b, $sign($b), []));
    }

    public function test_the_razorpay_return_signature_is_verified_and_tampering_is_caught(): void
    {
        $this->razorpay();
        $q = ['razorpay_payment_id' => 'pay_XYZ', 'razorpay_payment_link_id' => 'plink_ABC', 'razorpay_payment_link_reference_id' => 'GPABCDEF0123456789AB', 'razorpay_payment_link_status' => 'paid'];
        $q['razorpay_signature'] = hash_hmac('sha256', 'plink_ABC|GPABCDEF0123456789AB|paid|pay_XYZ', self::RZP_SECRET);

        $e = $this->gateway('razorpay')->handleReturn($q, []);

        self::assertSame([PaymentEvent::PAID, 'GPABCDEF0123456789AB', 'pay_XYZ', null], [$e->kind, $e->reference, $e->providerPaymentId, $e->amountMinor]);
        self::assertNull($this->gateway('razorpay')->handleReturn([], []), 'a plain visit with no signature is just a page view');

        $forged = $q;
        $forged['razorpay_payment_link_status'] = 'paid';
        $forged['razorpay_payment_id'] = 'pay_OTHER';
        $this->expectException(InvalidSignature::class);
        $this->gateway('razorpay')->handleReturn($forged, []);
    }

    public function test_an_unpaid_razorpay_return_is_not_a_payment(): void
    {
        $this->razorpay();
        $q = ['razorpay_payment_id' => '', 'razorpay_payment_link_id' => 'plink_ABC', 'razorpay_payment_link_reference_id' => 'GPX', 'razorpay_payment_link_status' => 'cancelled'];
        $q['razorpay_signature'] = hash_hmac('sha256', 'plink_ABC|GPX|cancelled|', self::RZP_SECRET);

        self::assertNull($this->gateway('razorpay')->handleReturn($q, []));
    }

    public function test_razorpay_connection_test(): void
    {
        $this->razorpay();
        foreach ([[200, true, 'Connected'], [401, false, 'rejected']] as [$status, $ok, $needle]) {
            $this->http->push(['status' => $status]);
            $r = $this->gateway('razorpay')->test();
            self::assertSame($ok, $r['ok']);
            self::assertStringContainsString($needle, $r['message']);
        }
    }

    public function test_an_unconfigured_or_switched_off_gateway_is_never_handed_out(): void
    {
        $registry = $this->app->get(\App\Payments\GatewayRegistry::class);
        self::assertNull($registry->usable('stripe'));
        self::assertNull($registry->usable('nonsense'));

        $this->stripe();
        self::assertNotNull($registry->usable('stripe'));
        $this->app->get(\App\Integrations\Credentials::class)->save('stripe', [\App\Integrations\Credentials::ENABLED => '0'], $this->user('super_admin'));
        self::assertNull($this->app->get(\App\Payments\GatewayRegistry::class)->usable('stripe'), 'switched off in the panel');
    }
}
