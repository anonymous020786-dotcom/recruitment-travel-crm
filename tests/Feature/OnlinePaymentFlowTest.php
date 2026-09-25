<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\BranchScope;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\Invoice;
use App\Models\User;
use App\Payments\OnlinePaymentService;
use App\Payments\PaymentEvent;
use App\Repositories\GatewayPaymentRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\UserRepository;
use App\Services\EmployerService;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Validators\InvoiceValidator;
use App\Validators\JobValidator;
use Tests\Support\GatewayTestCase;

/** Collecting an invoice online end to end: pay links, the customer's page, verified webhooks/returns, and the ledger. */
final class OnlinePaymentFlowTest extends GatewayTestCase
{
    private const WHSEC = 'whsec_flow_test_secret_123456';
    private const RZP_WEBHOOK = 'rzp_flow_webhook_123456';
    private const RZP_KEY = 'rzp_flow_key_secret_abcdef';

    private ArraySessionStore $store;
    private Router $router;
    private string $sid = '';
    private string $token = '';
    private int $seq = 0;
    /** @var list<int> */
    private array $personIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        $this->configure('stripe', ['publishable_key' => 'pk_test_1', 'secret_key' => 'sk_test_abcdefghijklmnop', 'webhook_secret' => self::WHSEC]);
        $this->configure('razorpay', ['key_id' => 'rzp_test_1', 'key_secret' => self::RZP_KEY, 'webhook_secret' => self::RZP_WEBHOOK, 'mode' => 'test']);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->personIds !== []) {
            $ph = implode(',', array_fill(0, count($this->personIds), '?'));
            $this->db->affectingStatement("DELETE FROM persons WHERE id IN ({$ph})", $this->personIds);
        }
    }

    // ---- fixtures --------------------------------------------------------------------------------------------------------

    private function service(): OnlinePaymentService
    {
        return $this->app->get(OnlinePaymentService::class);
    }

    private function invoice(User $actor, string $amount = '5000', string $currency = 'inr'): Invoice
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'Ravi Kumar', 'phone' => '86' . str_pad((string) (random_int(1000, 9999) * 10000 + ++$this->seq), 8, '0', STR_PAD_LEFT), 'priority' => 'medium'], $actor, $this->branch, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'GWX Co', 'country' => 'AE', 'status' => 'active'], $actor, $this->branch);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);
        $application = $this->app->get(\App\Services\ApplicationService::class)->create($c, $job, $actor);

        $invoices = $this->app->get(InvoiceService::class);
        $draft = $invoices->createForApplication($application, (new InvoiceValidator())->invoice([
            'currency' => $currency, 'line_description' => ['Recruitment fee'], 'line_quantity' => ['1'], 'line_unit_price' => [$amount],
        ]), $actor);
        $issued = $invoices->issue($draft, $actor, $draft->recordVersion);

        return $this->app->get(InvoiceRepository::class)->findById($issued->id, BranchScope::orgWide());
    }

    private function fresh(Invoice $i): Invoice
    {
        return $this->app->get(InvoiceRepository::class)->findById($i->id, BranchScope::orgWide());
    }

    /** @return array<string,mixed> the link row */
    private function link(string $publicId): array
    {
        return (array) $this->app->get(GatewayPaymentRepository::class)->findByPublicId($publicId);
    }

    private function paymentCount(Invoice $i): int
    {
        return (int) $this->db->selectValue("SELECT COUNT(*) FROM payments WHERE person_id = ? AND status = 'recorded'", [$i->personId]);
    }

    /** A signed Stripe "paid" webhook for a link. @return array{0:string,1:array<string,string>} */
    private function stripePaid(array $link, string $paymentIntent = 'pi_1', ?int $minor = null, string $currency = 'INR', string $event = 'evt_1'): array
    {
        $body = (string) json_encode(['id' => $event, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_1', 'payment_status' => 'paid', 'payment_intent' => $paymentIntent, 'client_reference_id' => $link['reference'],
            'amount_total' => $minor ?? (int) round((float) $link['amount'] * 100), 'currency' => strtolower($currency),
        ]]]);
        $t = time();

        return [$body, ['stripe-signature' => "t={$t},v1=" . hash_hmac('sha256', $t . '.' . $body, self::WHSEC)]];
    }

    // ---- creating a link -----------------------------------------------------------------------------------------------------

    public function test_a_pay_link_is_created_for_the_balance_or_an_instalment_and_is_audited(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');

        $full = $this->link($this->service()->createLink($inv, 'razorpay', null, $staff));
        $part = $this->link($this->service()->createLink($inv, 'razorpay', '1500.50', $staff));

        self::assertSame(['5000.00', 'INR', 'created', 'razorpay'], [$full['amount'], $full['currency'], $full['status'], $full['gateway']]);
        self::assertSame('1500.50', $part['amount']);
        self::assertMatchesRegularExpression('/^GP[0-9A-F]{18}$/', $full['reference'], 'our merchant order id: short enough for PayU, unguessable');
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $full['public_id']);
        self::assertGreaterThan(time() + 6 * 86400, strtotime($full['expires_at'] . ' UTC'));
        self::assertSame(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'online_payment_link_created'"));
        self::assertSame([], $this->http->calls, 'nothing is sent to the gateway until the customer presses Pay');
    }

    public function test_link_creation_rules(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $svc = $this->service();

        foreach ([
            'over the balance' => ['razorpay', '5000.01'], 'zero' => ['razorpay', '0'], 'text' => ['razorpay', 'ten'], 'negative' => ['razorpay', '-5'], 'three decimals' => ['razorpay', '1.234'],
        ] as $label => [$gw, $amount]) {
            try {
                $svc->createLink($inv, $gw, $amount, $staff);
                self::fail("accepted {$label}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        try {
            $svc->createLink($inv, 'stripe', null, $staff);   // Stripe is configured and can do INR — allowed
            $svc->createLink($this->invoice($staff, '100', 'usd'), 'razorpay', null, $staff);
            self::fail('Razorpay collected dollars');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('cannot collect USD', $e->getMessage());
        }
        try {
            $svc->createLink($inv, 'payu', null, $staff);
            self::fail('used an unconfigured gateway');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('not set up', $e->getMessage());
        }
        $this->expectException(AuthorizationException::class);
        $svc->createLink($inv, 'razorpay', null, $this->user('counselor'));   // no payments.create
    }

    public function test_only_an_issued_invoice_with_a_balance_can_be_paid_online(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $this->db->affectingStatement("UPDATE invoices SET status = 'void' WHERE id = ?", [$inv->id]);

        $this->expectException(DomainRuleException::class);
        $this->service()->createLink($this->fresh($inv), 'razorpay', null, $staff);
    }

    public function test_someone_in_another_branch_cannot_create_a_link(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff);
        $otherBranch = (int) $this->db->insertRow('branches', ['public_id' => \App\Support\Ulid::generate(), 'name' => 'GW other', 'code' => 'GWX-O' . bin2hex(random_bytes(2))]);

        $this->expectException(AuthorizationException::class);
        $this->service()->createLink($inv, 'razorpay', null, $this->user('manager', $otherBranch));
    }

    // ---- the customer's page ---------------------------------------------------------------------------------------------------

    public function test_the_public_page_shows_only_what_the_customer_needs(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $id = $this->service()->createLink($inv, 'razorpay', null, $staff);

        $page = $this->service()->page($id);

        self::assertSame(['INR', '5000.00', true, 'Ravi'], [$page['currency'], $page['amount'], $page['payable'], $page['customer']]);
        self::assertSame($inv->invoiceNumber, $page['invoice_number']);
        self::assertStringNotContainsString('Kumar', json_encode($page), 'only the first name');
        self::assertNull($this->service()->page('01ZZZZZZZZZZZZZZZZZZZZZZZZ'));
        self::assertNull($this->service()->page('nonsense'));
    }

    public function test_expired_cancelled_and_settled_links_are_not_payable(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $svc = $this->service();

        $expired = $svc->createLink($inv, 'razorpay', null, $staff);
        $this->db->affectingStatement("UPDATE gateway_payments SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE public_id = ?", [$expired]);
        self::assertSame(['expired', false], [$svc->page($expired)['status'], $svc->page($expired)['payable']]);

        $cancelled = $svc->createLink($inv, 'razorpay', null, $staff);
        $svc->cancel($this->link($cancelled), $staff);
        self::assertSame(['cancelled', false], [$svc->page($cancelled)['status'], $svc->page($cancelled)['payable']]);

    }

    // ---- starting the checkout ----------------------------------------------------------------------------------------------------

    public function test_pressing_pay_creates_the_checkout_now_with_our_reference_and_urls(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $id = $this->service()->createLink($inv, 'razorpay', null, $staff);
        $this->http->push(['status' => 200, 'json' => ['id' => 'plink_1', 'short_url' => 'https://rzp.io/i/x']]);

        $checkout = $this->service()->start($id);

        $body = $this->http->lastBody();
        $row = $this->link($id);
        self::assertSame($row['reference'], $body['reference_id']);
        self::assertSame(500000, $body['amount']);
        self::assertStringEndsWith('/pay/' . $id . '/return', $body['callback_url']);
        self::assertSame(['pending', 'plink_1', 'redirect', 'https://rzp.io/i/x'], [$row['status'], $row['provider_order_id'], $row['checkout_method'], $row['checkout_url']]);
        self::assertSame('https://rzp.io/i/x', $checkout->url);
    }

    public function test_a_gateway_outage_leaves_the_link_usable_and_a_dead_link_cannot_start(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $id = $this->service()->createLink($inv, 'razorpay', null, $staff);
        $this->http->push(['status' => 503]);

        try {
            $this->service()->start($id);
            self::fail('a 503 was swallowed');
        } catch (\App\Payments\GatewayException) {
            self::assertSame('created', $this->link($id)['status'], 'the customer can simply try again');
        }

        $this->service()->cancel($this->link($id), $staff);
        $this->expectException(DomainRuleException::class);
        $this->service()->start($id);
    }

    // ---- webhooks: the money -------------------------------------------------------------------------------------------------------

    public function test_a_verified_paid_webhook_records_the_payment_once_and_settles_the_invoice(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $link = $this->link($this->service()->createLink($inv, 'stripe', null, $staff));
        [$body, $headers] = $this->stripePaid($link);

        $r = $this->service()->webhook('stripe', $body, $headers, []);

        self::assertSame(['status' => 200, 'result' => 'paid'], $r);
        $payment = $this->db->selectOne('SELECT * FROM payments WHERE person_id = ?', [$inv->personId]);
        self::assertSame(['5000.00', 'card', 'INR', 'recorded'], [$payment['amount'], $payment['method'], $payment['currency'], $payment['status']]);
        self::assertStringContainsString('Stripe pi_1', (string) $payment['reference']);
        self::assertSame(hash('sha256', 'gw:stripe:pi_1'), $payment['idempotency_key']);
        self::assertSame('paid', $this->fresh($inv)->status);
        $row = $this->link($link['public_id']);
        self::assertSame(['paid', 'pi_1', (int) $payment['id']], [$row['status'], $row['provider_payment_id'], (int) $row['payment_id']]);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'online_payment' AND title = 'Online payment received'", [$staff->id]));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'online_payment_recorded'"));
    }

    public function test_a_replayed_or_redelivered_webhook_never_records_the_money_twice(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $link = $this->link($this->service()->createLink($inv, 'stripe', null, $staff));
        [$body, $headers] = $this->stripePaid($link);

        self::assertSame('paid', $this->service()->webhook('stripe', $body, $headers, [])['result']);
        self::assertSame('duplicate', $this->service()->webhook('stripe', $body, $headers, [])['result'], 'the exact same delivery again');

        // Stripe re-sends the event with a new id / body for the same payment: still one payment
        [$body2, $headers2] = $this->stripePaid($link, 'pi_1', null, 'INR', 'evt_2');
        self::assertNotSame($body, $body2);
        self::assertSame('ignored', $this->service()->webhook('stripe', $body2, $headers2, [])['result']);
        self::assertSame(1, $this->paymentCount($inv));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM gateway_events WHERE gateway = 'stripe' AND signature_ok = 1 AND result = 'paid'"));
    }

    public function test_a_forged_webhook_is_refused_logged_and_changes_nothing(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $link = $this->link($this->service()->createLink($inv, 'stripe', null, $staff));
        [$body] = $this->stripePaid($link);

        $r = $this->service()->webhook('stripe', $body, ['stripe-signature' => 't=' . time() . ',v1=' . str_repeat('a', 64)], []);
        $again = $this->service()->webhook('stripe', $body, ['stripe-signature' => 't=' . time() . ',v1=' . str_repeat('b', 64)], []);

        self::assertSame(['status' => 401, 'result' => 'bad_signature'], $r);
        self::assertSame(401, $again['status']);
        self::assertSame(0, $this->paymentCount($inv));
        self::assertSame('created', $this->link($link['public_id'])['status']);
        self::assertSame(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM gateway_events WHERE result = 'bad_signature' AND signature_ok = 0"), 'every forged attempt is kept for review');
    }

    public function test_a_payment_for_the_wrong_amount_or_currency_is_set_aside_and_staff_are_told(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $svc = $this->service();

        $link = $this->link($svc->createLink($inv, 'stripe', null, $staff));
        [$body, $headers] = $this->stripePaid($link, 'pi_low', 100);   // 1.00 instead of 5,000.00
        self::assertSame('mismatch', $svc->webhook('stripe', $body, $headers, [])['result']);
        self::assertSame(0, $this->paymentCount($inv), 'not recorded on a guess');
        self::assertSame('mismatch', $this->link($link['public_id'])['status']);
        self::assertStringContainsString('was requested', (string) $this->link($link['public_id'])['failure_reason']);

        $link2 = $this->link($svc->createLink($inv, 'stripe', null, $staff));
        [$body2, $headers2] = $this->stripePaid($link2, 'pi_usd', 500000, 'USD');
        self::assertSame('mismatch', $svc->webhook('stripe', $body2, $headers2, [])['result']);
        self::assertSame(0, $this->paymentCount($inv));
        self::assertSame(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Online payment needs attention'", [$staff->id]));
    }

    public function test_an_unknown_reference_is_acknowledged_but_never_recorded(): void
    {
        $body = (string) json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_x', 'payment_status' => 'paid', 'payment_intent' => 'pi_x', 'client_reference_id' => 'GPNOSUCHREFERENCE000', 'amount_total' => 100, 'currency' => 'inr']]]);
        $t = time();

        $r = $this->service()->webhook('stripe', $body, ['stripe-signature' => "t={$t},v1=" . hash_hmac('sha256', $t . '.' . $body, self::WHSEC)], []);

        self::assertSame(['status' => 200, 'result' => 'unknown_payment'], $r, 'a 200 stops the gateway retrying a message that is not ours');
    }

    public function test_if_the_link_creator_can_no_longer_record_payments_the_money_is_flagged_not_lost_track_of(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $link = $this->link($this->service()->createLink($inv, 'stripe', null, $staff));
        $this->db->affectingStatement('UPDATE users SET is_active = 0 WHERE id = ?', [$staff->id]);
        $this->db->affectingStatement("UPDATE users SET role_id = ? WHERE id = ?", [$this->roles['counselor'], $staff->id]);   // lost payments.create
        $this->app->get(PermissionService::class)->forget($staff->id);   // (a real request is a fresh process; the test shares one)
        [$body, $headers] = $this->stripePaid($link);

        $r = $this->service()->webhook('stripe', $body, $headers, []);

        self::assertSame('mismatch', $r['result']);
        self::assertSame(0, $this->paymentCount($inv));
        self::assertStringContainsString('Received but not recorded', (string) $this->link($link['public_id'])['failure_reason']);
    }

    public function test_failed_then_retried_works_but_a_paid_link_is_never_downgraded_and_a_second_payment_is_flagged(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $link = $this->link($this->service()->createLink($inv, 'stripe', null, $staff));
        $failed = new PaymentEvent(PaymentEvent::FAILED, $link['reference'], null, null, null, null, 'card', 'Card declined');

        self::assertSame('failed', $this->service()->handleEvent('stripe', $failed));
        self::assertSame(['failed', 'Card declined'], [$this->link($link['public_id'])['status'], $this->link($link['public_id'])['failure_reason']]);

        [$body, $headers] = $this->stripePaid($link, 'pi_ok');
        self::assertSame('paid', $this->service()->webhook('stripe', $body, $headers, [])['result'], 'the customer tried again and it worked');
        $this->service()->handleEvent('stripe', $failed);
        self::assertSame('paid', $this->link($link['public_id'])['status'], 'a late failure message cannot undo a paid link');

        [$body2, $headers2] = $this->stripePaid($link, 'pi_second', null, 'INR', 'evt_second');
        self::assertSame('second_payment', $this->service()->webhook('stripe', $body2, $headers2, [])['result'], 'the invoice is settled, so the books cannot take a second payment');
        self::assertSame(1, $this->paymentCount($inv));
        self::assertSame(['paid', 'pi_ok'], [$this->link($link['public_id'])['status'], $this->link($link['public_id'])['provider_payment_id']], 'the paid link is not downgraded');
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Second online payment needs attention'", [$staff->id]));
    }

    public function test_an_instalment_leaves_the_invoice_partially_paid(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $link = $this->link($this->service()->createLink($inv, 'stripe', '2000', $staff));
        [$body, $headers] = $this->stripePaid($link, 'pi_part');

        self::assertSame('paid', $this->service()->webhook('stripe', $body, $headers, [])['result']);

        $after = $this->fresh($inv);
        self::assertSame(['partially_paid', '3000.00'], [$after->status, $after->outstanding()]);
    }

    // ---- browser returns -------------------------------------------------------------------------------------------------------------

    public function test_a_verified_razorpay_return_records_the_payment_and_a_tampered_one_does_not(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $id = $this->service()->createLink($inv, 'razorpay', null, $staff);
        $row = $this->link($id);
        $q = ['razorpay_payment_id' => 'pay_R1', 'razorpay_payment_link_id' => 'plink_9', 'razorpay_payment_link_reference_id' => $row['reference'], 'razorpay_payment_link_status' => 'paid'];

        $bad = $q + ['razorpay_signature' => hash_hmac('sha256', 'nonsense', self::RZP_KEY)];
        self::assertSame(['status' => 'created', 'verified' => false], $this->service()->customerReturned($id, $bad, []));
        self::assertSame(0, $this->paymentCount($inv));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'online_payment_bad_return'"));

        $good = $q + ['razorpay_signature' => hash_hmac('sha256', "plink_9|{$row['reference']}|paid|pay_R1", self::RZP_KEY)];
        self::assertSame(['status' => 'paid', 'verified' => true], $this->service()->customerReturned($id, $good, []));
        self::assertSame(1, $this->paymentCount($inv));
        // the webhook for the same payment arrives afterwards: still one payment
        $body = (string) json_encode(['event' => 'payment_link.paid', 'payload' => ['payment_link' => ['entity' => ['id' => 'plink_9', 'reference_id' => $row['reference']]], 'payment' => ['entity' => ['id' => 'pay_R1', 'amount' => 500000, 'currency' => 'INR', 'method' => 'upi']]]]);
        self::assertSame('ignored', $this->service()->webhook('razorpay', $body, ['x-razorpay-signature' => hash_hmac('sha256', $body, self::RZP_WEBHOOK)], [])['result']);
        self::assertSame(1, $this->paymentCount($inv));
    }

    // ---- over HTTP ---------------------------------------------------------------------------------------------------------------------

    private function actAs(?User $user): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($user !== null ? ['_auth_user_id' => $user->id, '_auth_at' => time(), '_authenticated_at' => time()] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $gate = new Gate($this->app, $this->app->get(PermissionService::class), $auth);
        $gate->policy(Invoice::class, \App\Policies\InvoicePolicy::class);
        $gate->policy(\App\Models\Payment::class, \App\Policies\PaymentPolicy::class);
        $this->app->instance(Gate::class, $gate);
    }

    /** @param array<string,mixed> $post @param array<string,string> $server */
    private function send(string $method, string $uri, array $post = [], string $raw = '', array $server = [], array $query = []): Response
    {
        if ($post !== [] && !isset($post['_token']) && $server === []) {
            $post['_token'] = $this->token;
        }

        return $this->router->dispatch(new Request($query, $post, ['crm_session' => $this->sid], [], $server + [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], $raw));
    }

    private function code(string $method, string $uri, array $post = [], string $raw = '', array $server = []): int
    {
        try {
            return $this->send($method, $uri, $post, $raw, $server)->getStatus();
        } catch (AuthorizationException) {
            return 403;
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_the_pay_page_is_public_private_to_caches_and_not_indexed_and_go_redirects_to_the_gateway(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $id = $this->service()->createLink($inv, 'razorpay', null, $staff);
        $this->actAs(null);

        $page = $this->send('GET', "/pay/{$id}");
        self::assertSame(200, $page->getStatus());
        self::assertStringContainsString('INR 5,000.00', $page->getBody());
        self::assertStringContainsString('Pay INR 5,000.00 securely', $page->getBody());
        self::assertStringContainsString('method="post" action="/pay/' . $id . '/go"', $page->getBody(), 'starting a payment is a POST, never a GET');
        self::assertStringContainsString('no-store', (string) $page->getHeader('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $page->getHeader('X-Robots-Tag'));
        self::assertSame(404, $this->code('GET', '/pay/01ZZZZZZZZZZZZZZZZZZZZZZZZ'));

        $this->http->push(['status' => 200, 'json' => ['id' => 'plink_1', 'short_url' => 'https://rzp.io/i/xyz']]);
        $go = $this->send('POST', "/pay/{$id}/go");
        self::assertSame(302, $go->getStatus());
        self::assertSame('https://rzp.io/i/xyz', $go->getHeader('Location'));

        $this->http->push(['status' => 500]);
        self::assertSame(502, $this->send('POST', "/pay/{$id}/go")->getStatus(), 'a gateway outage shows a friendly retry page');
        self::assertStringContainsString('not available right now', $this->send('POST', "/pay/{$id}/go")->getBody());
    }

    public function test_the_webhook_endpoint_needs_no_session_or_csrf_but_a_valid_signature(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $link = $this->link($this->service()->createLink($inv, 'stripe', null, $staff));
        [$body, $headers] = $this->stripePaid($link);
        $this->actAs(null);
        $server = ['HTTP_STRIPE_SIGNATURE' => $headers['stripe-signature'], 'CONTENT_TYPE' => 'application/json'];

        $res = $this->send('POST', '/webhooks/stripe', [], $body, $server);

        self::assertSame(200, $res->getStatus());
        self::assertSame('paid', json_decode($res->getBody(), true)['result']);
        self::assertSame(1, $this->paymentCount($inv));
        self::assertSame(401, $this->code('POST', '/webhooks/stripe', [], $body . ' ', $server), 'a changed body no longer matches its signature');
        self::assertSame(404, $this->code('POST', '/webhooks/nosuchgateway', [], '{}'));
        $this->app->get(\App\Integrations\Credentials::class)->save('stripe', [\App\Integrations\Credentials::ENABLED => '0'], $this->user('super_admin'));
        self::assertSame(409, $this->code('POST', '/webhooks/stripe', [], $body, $server), 'a known gateway that is switched off');
        self::assertSame(413, $this->code('POST', '/webhooks/stripe', [], str_repeat('x', 70000)));
        self::assertContains($this->code('GET', '/webhooks/stripe'), [404, 405]);
    }

    public function test_staff_create_a_link_from_the_invoice_and_the_card_shows_it(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $this->actAs($staff);

        $before = $this->send('GET', "/invoices/{$inv->publicId}")->getBody();
        self::assertStringContainsString('Collect online', $before);
        self::assertStringContainsString('Razorpay', $before);

        $res = $this->send('POST', "/invoices/{$inv->publicId}/online-payments", ['gateway' => 'razorpay', 'amount' => '']);
        self::assertSame("/invoices/{$inv->publicId}#online", $res->getHeader('Location'));
        $row = (array) $this->db->selectOne('SELECT * FROM gateway_payments WHERE invoice_id = ?', [$inv->id]);
        self::assertSame(['razorpay', 'created', $staff->id], [$row['gateway'], $row['status'], (int) $row['created_by']]);

        $after = $this->send('GET', "/invoices/{$inv->publicId}")->getBody();
        self::assertStringContainsString('/pay/' . $row['public_id'], $after);
        self::assertStringContainsString('Created', $after);

        $this->send('POST', "/online-payments/{$row['public_id']}/cancel", ['x' => '1']);
        self::assertSame('cancelled', $this->link($row['public_id'])['status']);
    }

    public function test_someone_without_the_permission_or_in_another_branch_cannot_use_the_staff_side(): void
    {
        $staff = $this->user('manager');
        $inv = $this->invoice($staff, '5000');
        $id = $this->service()->createLink($inv, 'razorpay', null, $staff);
        $otherBranch = (int) $this->db->insertRow('branches', ['public_id' => \App\Support\Ulid::generate(), 'name' => 'GW other', 'code' => 'GWX-P' . bin2hex(random_bytes(2))]);

        $this->actAs($this->user('manager', $otherBranch));
        self::assertSame(404, $this->code('POST', "/invoices/{$inv->publicId}/online-payments", ['gateway' => 'razorpay']));
        self::assertSame(404, $this->code('POST', "/online-payments/{$id}/cancel", ['x' => '1']), 'a link in another branch looks missing');
        self::assertSame('created', $this->link($id)['status']);

        $this->actAs($this->user('counselor'));
        self::assertSame(403, $this->code('POST', "/online-payments/{$id}/cancel", ['x' => '1']));
    }

    public function test_the_rate_limit_buckets_for_the_public_endpoints_exist(): void
    {
        $b = (array) $this->app->config()->get('rate_limits.buckets');

        self::assertSame(['ip'], $b['pay_public']['by']);
        self::assertSame(['ip'], $b['webhook']['by']);
        self::assertGreaterThan($b['pay_public']['limit'], $b['webhook']['limit'], 'gateways retry in bursts: the webhook allowance is the larger');
    }
}
