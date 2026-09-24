<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\InvoiceHistoryRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentAllocationRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ReceiptRepository;
use App\Repositories\UserRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\PaymentService;
use App\Support\Hash;
use App\Support\ListQuery;
use App\Support\Ulid;
use App\Validators\InvoiceValidator;
use App\Validators\JobValidator;
use App\Validators\PaymentValidator;
use Tests\Support\DbTestCase;

final class PaymentServiceTest extends DbTestCase
{
    private PaymentService $service;
    private InvoiceService $invoiceService;
    private PaymentRepository $repo;
    private InvoiceRepository $invoices;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(PaymentService::class);
        $this->invoiceService = $this->app->get(InvoiceService::class);
        $this->repo = $this->app->get(PaymentRepository::class);
        $this->invoices = $this->app->get(InvoiceRepository::class);
        $this->branchA = $this->branch('PYX-A');
        $this->branchB = $this->branch('PYX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'PYX-%')";
        $this->db->affectingStatement("DELETE FROM refunds WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM receipts WHERE payment_id IN (SELECT id FROM payments WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM payment_allocations WHERE payment_id IN (SELECT id FROM payments WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM payments WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM invoices WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('payments', 'invoices', 'applications', 'jobs', 'employers', 'leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        if ($this->personIds !== []) {
            $ph = implode(',', array_fill(0, count($this->personIds), '?'));
            $this->db->affectingStatement("DELETE FROM persons WHERE id IN ({$ph})", $this->personIds);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'PYX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(invoice|payment|receipt|job|employer|lead|candidate|application):'");
    }

    // ---- fixtures --------------------------------------------------

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2))]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'py_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function scope(?User $for = null): \App\Auth\BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($for ?? $this->app->get(UserRepository::class)->findById($this->userIds[0]));
    }

    private function application(User $actor): Application
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'PYX Cand', 'phone' => '91' . random_int(10000000, 99999999), 'priority' => 'medium'], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'PYX Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);

        return $this->app->get(ApplicationService::class)->create($c, $job, $actor);
    }

    /** An issued invoice of $amount (a single line) for the application. */
    private function issued(User $actor, Application $app, string $amount = '1000', string $currency = 'inr'): Invoice
    {
        $draft = $this->invoiceService->createForApplication($app, (new InvoiceValidator())->invoice([
            'currency' => $currency, 'line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => [$amount],
        ]), $actor);

        return $this->invoiceService->issue($draft, $actor, $draft->recordVersion);
    }

    /** @param array<string,mixed> $extra */
    private function input(string $amount, array $extra = []): array
    {
        return (new PaymentValidator())->payment($extra + ['amount' => $amount, 'method' => 'cash']);
    }

    private function pay(User $actor, Invoice $invoice, string $amount, array $extra = []): Payment
    {
        return $this->service->recordForInvoice($invoice, $this->input($amount, $extra), $actor)['payment'];
    }

    private function fresh(Invoice $i): Invoice
    {
        return $this->invoices->findById($i->id, $this->scope());
    }

    private function trail(Invoice $i): array
    {
        return array_reverse(array_map(static fn (array $h): string => $h['to'], $this->app->get(InvoiceHistoryRepository::class)->forInvoice($i->id)));
    }

    // ---- recording -------------------------------------------------

    public function test_a_full_payment_settles_the_invoice_and_issues_an_immutable_receipt(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '1000');

        $result = $this->service->recordForInvoice($inv, $this->input('1000', ['method' => 'upi', 'reference' => 'UTR123456', 'notes' => 'Paid at counter']), $actor);
        $p = $result['payment'];

        self::assertTrue($result['created']);
        self::assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $p->paymentNumber);
        self::assertMatchesRegularExpression('/^RCT-\d{4}-\d{6}$/', $p->receiptNumber);
        self::assertSame('1000.00', $p->amount);
        self::assertSame('INR', $p->currency, 'always in the invoice currency');
        self::assertSame('recorded', $p->status);
        self::assertSame('1000.00', $p->allocated);
        self::assertSame(0, $p->unallocatedMinor());
        self::assertSame($inv->personId, $p->personId);
        self::assertSame($this->branchA, $p->branchId);

        $paid = $this->fresh($inv);
        self::assertSame('paid', $paid->status);
        self::assertSame('1000.00', $paid->amountPaid);
        self::assertSame('0.00', $paid->outstanding());
        self::assertSame(['draft', 'issued', 'paid'], $this->trail($paid));

        $receipt = $this->app->get(ReceiptRepository::class)->forPayment($p->id);
        self::assertSame($p->receiptNumber, $receipt['receipt_number']);
        self::assertSame('1000.00', $receipt['snapshot']['amount']);
        self::assertSame('UTR123456', $receipt['snapshot']['reference']);
        self::assertSame($inv->invoiceNumber, $receipt['snapshot']['allocations'][0]['invoice_number']);
        self::assertSame('PYX Cand', $receipt['snapshot']['customer']['name']);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='payments' AND action='created' AND record_id = ?", [$p->id]));
    }

    public function test_partial_payments_walk_the_invoice_to_paid(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '1000');

        $this->pay($actor, $inv, '400');
        $mid = $this->fresh($inv);
        self::assertSame('partially_paid', $mid->status);
        self::assertSame('600.00', $mid->outstanding());

        $this->pay($actor, $mid, '600');
        self::assertSame('paid', $this->fresh($inv)->status);
        self::assertSame(['draft', 'issued', 'partially_paid', 'paid'], $this->trail($inv));
    }

    public function test_an_overpayment_is_capped_at_the_outstanding_amount_and_the_rest_is_credit(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '1000');

        $p = $this->pay($actor, $inv, '1500');

        self::assertSame('1500.00', $p->amount);
        self::assertSame('1000.00', $p->allocated, 'only what the invoice owed is applied');
        self::assertSame('500.00', $p->unallocated());
        self::assertSame('paid', $this->fresh($inv)->status);
        self::assertSame('1000.00', $this->fresh($inv)->amountPaid, 'the invoice never records more than it is worth');
        self::assertCount(1, $this->repo->withCreditForPerson($inv->personId, 'INR', $this->scope()));
        self::assertSame(1, $this->repo->paginate(ListQuery::of(['filters' => ['credit' => 'unallocated']]), $this->scope())->total);
    }

    public function test_the_same_idempotency_key_never_records_twice(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '1000');
        $key = 'key-' . bin2hex(random_bytes(8));

        $first = $this->service->recordForInvoice($inv, $this->input('300', ['idempotency_key' => $key]), $actor);
        $again = $this->service->recordForInvoice($this->fresh($inv), $this->input('300', ['idempotency_key' => $key]), $actor);

        self::assertTrue($first['created']);
        self::assertFalse($again['created']);
        self::assertSame($first['payment']->id, $again['payment']->id);
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM payments WHERE idempotency_key = ?', [$key]));
        self::assertSame('300.00', $this->fresh($inv)->amountPaid, 'the invoice was credited once');
    }

    public function test_only_an_issued_invoice_with_something_outstanding_can_be_paid(): void
    {
        $actor = $this->actor();
        $app = $this->application($actor);

        $draft = $this->invoiceService->createForApplication($app, (new InvoiceValidator())->invoice(['line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => ['100']]), $actor);
        $paid = $this->issued($actor, $app, '100');
        $this->pay($actor, $paid, '100');
        $toVoid = $this->issued($actor, $app, '100');
        $void = $this->invoiceService->void($toVoid, 'wrong', $actor, $toVoid->recordVersion);

        foreach (['draft' => $draft, 'paid' => $this->fresh($paid), 'void' => $void] as $why => $invoice) {
            try {
                $this->pay($actor, $invoice, '10');
                self::fail("a {$why} invoice cannot receive payments");
            } catch (DomainRuleException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM payments WHERE branch_id = ?", [$this->branchA]), 'nothing else was recorded');
    }

    public function test_validator_rules(): void
    {
        $v = new PaymentValidator();
        foreach ([
            'zero amount'            => ['amount' => '0', 'method' => 'cash'],
            'negative amount'        => ['amount' => '-5', 'method' => 'cash'],
            'text amount'            => ['amount' => 'ten', 'method' => 'cash'],
            'unknown method'         => ['amount' => '10', 'method' => 'barter'],
            'upi without reference'  => ['amount' => '10', 'method' => 'upi'],
            'cheque without number'  => ['amount' => '10', 'method' => 'cheque', 'reference' => '  '],
            'future date'            => ['amount' => '10', 'method' => 'cash', 'paid_at' => gmdate('Y-m-d\TH:i', strtotime('+3 days'))],
            'garbage date'           => ['amount' => '10', 'method' => 'cash', 'paid_at' => 'yesterday'],
            'bad key'                => ['amount' => '10', 'method' => 'cash', 'idempotency_key' => 'x'],
            'notes too long'         => ['amount' => '10', 'method' => 'cash', 'notes' => str_repeat('n', 501)],
        ] as $why => $input) {
            try {
                $v->payment($input);
                self::fail("accepted: {$why}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }

        $ok = $v->payment(['amount' => '250.5', 'method' => 'cash', 'paid_at' => '2026-01-05T10:30']);
        self::assertSame('250.50', $ok['amount']);
        self::assertSame('2026-01-05 10:30:00', $ok['paid_at']);
        self::assertNull($ok['reference']);

        self::assertSame('Duplicate entry', $v->reason(['reason' => '  Duplicate entry ']));
        $this->expectException(ValidationException::class);
        $v->reason(['reason' => '']);
    }

    // ---- allocation ------------------------------------------------

    public function test_credit_can_be_allocated_to_another_invoice_of_the_same_customer(): void
    {
        $actor = $this->actor();
        $app = $this->application($actor);
        $first = $this->issued($actor, $app, '1000');
        $second = $this->issued($actor, $app, '700');

        $p = $this->pay($actor, $first, '1500'); // 500 of credit
        $p = $this->service->allocate($p, $second, '500', $actor, $p->recordVersion);

        self::assertSame('1500.00', $p->allocated);
        self::assertSame(0, $p->unallocatedMinor());
        $s = $this->fresh($second);
        self::assertSame('partially_paid', $s->status);
        self::assertSame('200.00', $s->outstanding());
        self::assertCount(2, $this->app->get(PaymentAllocationRepository::class)->forPayment($p->id));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='payments' AND action='allocated' AND record_id = ?", [$p->id]));
    }

    public function test_allocation_limits(): void
    {
        $actor = $this->actor();
        $app = $this->application($actor);
        $first = $this->issued($actor, $app, '1000');
        $second = $this->issued($actor, $app, '300');
        $foreignCurrency = $this->issued($actor, $app, '300', 'aed');
        $other = $this->issued($actor, $this->application($actor), '300');

        $p = $this->pay($actor, $first, '1500'); // 500 credit

        $attempts = [
            'more than the credit left' => [$second, '600'],
            'more than the invoice owes' => [$second, '400'],
            'another currency'          => [$foreignCurrency, '100'],
            'another customer'          => [$other, '100'],
            'zero'                      => [$second, '0'],
        ];
        // "more than the invoice owes" only differs from the first case when credit exceeds the invoice
        $p2 = $this->pay($actor, $this->issued($actor, $app, '100'), '900'); // 800 credit, second owes 300
        foreach ($attempts as $why => [$invoice, $amount]) {
            $payment = $why === 'more than the invoice owes' ? $p2 : $p;
            try {
                $this->service->allocate($payment, $invoice, $amount, $actor, $payment->recordVersion);
                self::fail("refused: {$why}");
            } catch (DomainRuleException|ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('500.00', $this->repo->findById($p->id, $this->scope())->unallocated(), 'the failed attempts changed nothing');
    }

    public function test_a_stale_allocation_is_refused(): void
    {
        $actor = $this->actor();
        $app = $this->application($actor);
        $first = $this->issued($actor, $app, '100');
        $second = $this->issued($actor, $app, '500');
        $p = $this->pay($actor, $first, '400');

        $this->service->allocate($p, $second, '100', $actor, $p->recordVersion);
        $this->expectException(StaleRecordException::class);
        $this->service->allocate($p, $second, '100', $actor, $p->recordVersion);
    }

    // ---- reversal --------------------------------------------------

    public function test_reversing_a_payment_restores_the_invoice(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '1000');
        $p1 = $this->pay($actor, $inv, '400');
        $p2 = $this->pay($actor, $this->fresh($inv), '600');
        self::assertSame('paid', $this->fresh($inv)->status);

        $p2 = $this->service->reverse($p2, 'Cheque bounced', $actor, $p2->recordVersion);

        self::assertSame('reversed', $p2->status);
        self::assertSame('Cheque bounced', $p2->reversedReason);
        self::assertSame(0, $p2->unallocatedMinor(), 'a reversed payment holds no credit');
        $i = $this->fresh($inv);
        self::assertSame('partially_paid', $i->status);
        self::assertSame('400.00', $i->amountPaid);
        self::assertSame('600.00', $i->outstanding());
        self::assertCount(1, $this->app->get(PaymentAllocationRepository::class)->forPayment($p2->id), 'the ledger row is kept');

        $p1 = $this->service->reverse($this->repo->findById($p1->id, $this->scope()), 'Entered twice', $actor, $p1->recordVersion);
        self::assertSame('issued', $this->fresh($inv)->status);
        self::assertSame('0.00', $this->fresh($inv)->amountPaid);
        self::assertSame(['draft', 'issued', 'partially_paid', 'paid', 'partially_paid', 'issued'], $this->trail($inv));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='payments' AND action='reversed' AND record_id = ?", [$p1->id]));

        // with nothing paid any more, the invoice can be voided
        $void = $this->invoiceService->void($this->fresh($inv), 'Raised in error', $actor, $this->fresh($inv)->recordVersion);
        self::assertSame('void', $void->status);
    }

    public function test_reversal_rules(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '1000');
        $p = $this->pay($actor, $inv, '1000');

        try {
            $this->service->reverse($p, '  ', $actor, $p->recordVersion);
            self::fail('reason required');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $this->db->insertRow('refunds', [
            'public_id' => Ulid::generate(), 'refund_number' => 'RF-T-' . bin2hex(random_bytes(3)), 'payment_id' => $p->id, 'invoice_id' => $inv->id,
            'person_id' => $p->personId, 'branch_id' => $p->branchId, 'amount' => '100.00', 'method' => 'cash', 'reason' => 'test', 'status' => 'pending', 'created_by' => $actor->id,
        ]);
        try {
            $this->service->reverse($p, 'with a refund open', $actor, $p->recordVersion);
            self::fail('a payment with an open refund cannot be reversed');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
        $this->db->affectingStatement("UPDATE refunds SET status = 'rejected' WHERE payment_id = ?", [$p->id]);

        $rev = $this->service->reverse($p, 'ok now', $actor, $p->recordVersion);
        try {
            $this->service->reverse($rev, 'again', $actor, $rev->recordVersion);
            self::fail('reversal is final');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
        try {
            $this->service->allocate($rev, $inv, '10', $actor, $rev->recordVersion);
            self::fail('a reversed payment cannot be allocated');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
    }

    public function test_the_receipt_is_frozen_when_the_payment_is_reversed_or_edited(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '1000');
        $p = $this->pay($actor, $inv, '1000', ['method' => 'bank_transfer', 'reference' => 'ORIG-REF']);

        $p = $this->service->updateDetails($p, 'CORRECTED-REF', 'fixed a typo', $actor, $p->recordVersion);
        self::assertSame('CORRECTED-REF', $p->reference);
        $this->service->reverse($p, 'test', $actor, $p->recordVersion);

        $receipt = $this->service->receipt($this->repo->findById($p->id, $this->scope()), $actor);
        self::assertSame('ORIG-REF', $receipt['snapshot']['reference'], 'the receipt shows what was true when the money arrived');
        self::assertSame('1000.00', $receipt['snapshot']['amount']);

        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(ReceiptRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach ($methods as $name) {
            self::assertDoesNotMatchRegularExpression('/^(update|delete|remove|set|save)/i', $name, 'receipts are append-only');
        }
    }

    public function test_updating_details(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '1000');
        $p = $this->pay($actor, $inv, '500', ['method' => 'upi', 'reference' => 'UTR1']);

        try {
            $this->service->updateDetails($p, '', null, $actor, $p->recordVersion);
            self::fail('a UPI payment keeps its reference');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $p = $this->service->updateDetails($p, 'UTR2', 'note', $actor, $p->recordVersion);
        self::assertSame('500.00', $p->amount, 'money fields are untouched');
        try {
            $this->service->updateDetails($p, 'UTR3', null, $actor, 1);
            self::fail('stale');
        } catch (StaleRecordException) {
            self::assertTrue(true);
        }

        $rev = $this->service->reverse($p, 'test', $actor, $p->recordVersion);
        $this->expectException(DomainRuleException::class);
        $this->service->updateDetails($rev, 'UTR4', null, $actor, $rev->recordVersion);
    }

    // ---- permissions -----------------------------------------------

    public function test_permissions_and_branch_scope(): void
    {
        $manager = $this->actor();
        $inv = $this->issued($manager, $this->application($manager), '1000');
        $p = $this->pay($manager, $inv, '300');

        foreach (['read_only', 'counselor'] as $role) {
            $denied = $this->actor($role);
            foreach ([
                fn () => $this->service->recordForInvoice($inv, $this->input('10'), $denied),
                fn () => $this->service->allocate($p, $inv, '10', $denied, $p->recordVersion),
                fn () => $this->service->reverse($p, 'x', $denied, $p->recordVersion),
                fn () => $this->service->updateDetails($p, 'x', null, $denied, $p->recordVersion),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail("{$role} must not handle payments");
                } catch (AuthorizationException) {
                    self::assertTrue(true);
                }
            }
        }

        // the tours desk can take money and allocate it but cannot reverse
        $desk = $this->actor('travel');
        self::assertTrue($this->service->recordForInvoice($this->fresh($inv), $this->input('50'), $desk)['created']);
        try {
            $this->service->reverse($p, 'x', $desk, $p->recordVersion);
            self::fail('reversal needs payments.reverse');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        // another branch sees nothing and can do nothing
        $elsewhere = $this->actor('accounts', $this->branchB);
        self::assertNull($this->repo->findByPublicId($p->publicId, $this->scope($elsewhere)));
        self::assertSame(0, $this->repo->paginate(ListQuery::of([]), $this->scope($elsewhere))->total);
        try {
            $this->service->recordForInvoice($this->fresh($inv), $this->input('10'), $elsewhere);
            self::fail('outside the branch');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        try {
            $this->service->reverse($p, 'x', $elsewhere, $p->recordVersion);
            self::fail('outside the branch');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        // receipts are visible to whoever holds receipts.view in the branch; counselors have none
        self::assertNotNull($this->service->receipt($p, $this->actor('read_only')));
        try {
            $this->service->receipt($p, $this->actor('counselor'));
            self::fail('receipts.view required');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        // the accounts role runs the whole desk
        $accounts = $this->actor('accounts');
        $mine = $this->pay($accounts, $this->fresh($inv), '100');
        self::assertSame('reversed', $this->service->reverse($mine, 'test', $accounts, $mine->recordVersion)->status);
    }

    // ---- register --------------------------------------------------

    public function test_register_search_filters_and_sorting(): void
    {
        $actor = $this->actor();
        $inv = $this->issued($actor, $this->application($actor), '5000');
        $a = $this->pay($actor, $inv, '1000', ['method' => 'upi', 'reference' => 'UTR-FIND-ME']);
        $b = $this->pay($actor, $this->fresh($inv), '2000');
        $c = $this->pay($actor, $this->fresh($inv), '500', ['method' => 'card', 'reference' => 'CARD-1']);
        $this->service->reverse($c, 'test', $actor, $c->recordVersion);
        $scope = $this->scope();

        $total = fn (array $q): int => $this->repo->paginate(ListQuery::of($q), $scope)->total;
        self::assertSame(3, $total([]));
        self::assertSame(1, $total(['search' => $a->paymentNumber]), 'by payment number');
        self::assertSame(1, $total(['search' => $b->receiptNumber]), 'by receipt number');
        self::assertSame(3, $total(['search' => 'PYX Cand']), 'by customer');
        self::assertSame(1, $total(['search' => 'FIND-ME']), 'by reference');
        self::assertSame(0, $total(['search' => 'nobody-at-all']));
        self::assertSame(1, $total(['filters' => ['status' => 'reversed']]));
        self::assertSame(2, $total(['filters' => ['status' => 'recorded']]));
        self::assertSame(1, $total(['filters' => ['method' => 'upi']]));
        self::assertSame(0, $total(['filters' => ['credit' => 'unallocated']]));

        $biggest = $this->repo->paginate(ListQuery::of(['sort' => 'amount', 'direction' => 'desc']), $scope);
        self::assertSame($b->id, $biggest->items[0]->id);
        self::assertNull($this->repo->findByIdempotencyKey('no-such-key', $scope));

        // the allocation form looks invoices up by number, within the viewer's branches
        self::assertSame($inv->id, $this->invoices->findByNumber($inv->invoiceNumber, $scope)?->id);
        self::assertNull($this->invoices->findByNumber($inv->invoiceNumber, $this->scope($this->actor('manager', $this->branchB))));
        self::assertNull($this->invoices->findByNumber('INV-1999-000000', $scope));
    }
}
