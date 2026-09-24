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
use App\Models\Refund;
use App\Models\User;
use App\Repositories\InvoiceHistoryRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundHistoryRepository;
use App\Repositories\RefundRepository;
use App\Repositories\UserRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\PaymentReminderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Support\Hash;
use App\Support\ListQuery;
use App\Support\Ulid;
use App\Validators\InvoiceValidator;
use App\Validators\JobValidator;
use App\Validators\PaymentValidator;
use App\Validators\RefundValidator;
use Tests\Support\DbTestCase;

final class RefundServiceTest extends DbTestCase
{
    private RefundService $service;
    private PaymentService $payments;
    private InvoiceService $invoiceService;
    private RefundRepository $repo;
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
        $this->service = $this->app->get(RefundService::class);
        $this->payments = $this->app->get(PaymentService::class);
        $this->invoiceService = $this->app->get(InvoiceService::class);
        $this->repo = $this->app->get(RefundRepository::class);
        $this->invoices = $this->app->get(InvoiceRepository::class);
        $this->branchA = $this->branch('RFX-A');
        $this->branchB = $this->branch('RFX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'RFX-%')";
        $this->db->affectingStatement("DELETE FROM tasks WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM refunds WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM receipts WHERE payment_id IN (SELECT id FROM payments WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM payment_allocations WHERE payment_id IN (SELECT id FROM payments WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM payments WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM invoices WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('refunds', 'payments', 'invoices', 'applications', 'jobs', 'employers', 'leads', 'candidates')");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'RFX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(invoice|payment|receipt|refund|job|employer|lead|candidate|application):'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'rf_' . bin2hex(random_bytes(4)) . '@dev.local',
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

    private ?User $fixtureManager = null;

    /** Applications are set up by a manager whatever role the test then exercises (accounts cannot create leads). */
    private function application(User $ignored): Application
    {
        $actor = $this->fixtureManager ??= $this->actor('manager');
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'RFX Cand', 'phone' => '90' . random_int(10000000, 99999999), 'priority' => 'medium'], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'RFX Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);

        return $this->app->get(ApplicationService::class)->create($c, $job, $actor);
    }

    private function issued(User $actor, Application $app, string $amount = '1000'): Invoice
    {
        $draft = $this->invoiceService->createForApplication($app, (new InvoiceValidator())->invoice([
            'currency' => 'inr', 'line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => [$amount],
        ]), $actor);

        return $this->invoiceService->issue($draft, $actor, $draft->recordVersion);
    }

    private function pay(User $actor, Invoice $invoice, string $amount): Payment
    {
        return $this->payments->recordForInvoice($invoice, (new PaymentValidator())->payment(['amount' => $amount, 'method' => 'cash']), $actor)['payment'];
    }

    /** @return array{0:Invoice,1:Payment} an invoice of 1000 fully paid by one payment of 1000 (or $paid) */
    private function paidInvoice(User $actor, string $paid = '1000'): array
    {
        $inv = $this->issued($actor, $this->application($actor));

        return [$inv, $this->pay($actor, $inv, $paid)];
    }

    /** @param array<string,mixed> $extra */
    private function input(string $amount, array $extra = []): array
    {
        return (new RefundValidator())->request($extra + ['amount' => $amount, 'method' => 'cash', 'reason' => 'Customer withdrew']);
    }

    private function fresh(Invoice $i): Invoice
    {
        return $this->invoices->findById($i->id, $this->scope());
    }

    private function freshPayment(Payment $p): Payment
    {
        return $this->app->get(PaymentRepository::class)->findById($p->id, $this->scope());
    }

    private function trail(Refund $r): array
    {
        return array_reverse(array_map(static fn (array $h): string => $h['to'], $this->app->get(RefundHistoryRepository::class)->forRefund($r->id)));
    }

    // ---- request ---------------------------------------------------

    public function test_a_refund_request_starts_pending_with_history_and_notifies_the_managers(): void
    {
        $accounts = $this->actor('accounts');
        $manager = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($accounts);

        $r = $this->service->request($p, $inv, $this->input('300'), $accounts);

        self::assertSame('pending', $r->status);
        self::assertMatchesRegularExpression('/^RF-\d{4}-\d{6}$/', $r->refundNumber);
        self::assertSame('300.00', $r->amount);
        self::assertSame('INR', $r->currency);
        self::assertSame($inv->id, $r->invoiceId);
        self::assertSame($p->personId, $r->personId);
        self::assertSame($accounts->id, $r->createdBy);
        self::assertSame(['pending'], $this->trail($r));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='refunds' AND action='created' AND record_id = ?", [$r->id]));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'refund_requested'", [$manager->id]));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'refund_requested'", [$accounts->id]), 'not the requester');
        self::assertSame('paid', $this->fresh($inv)->status, 'nothing moves until the refund is paid');
    }

    public function test_a_refund_can_never_exceed_what_the_payment_applied_to_the_invoice(): void
    {
        $actor = $this->actor();
        [$inv, $p] = $this->paidInvoice($actor); // 1000 applied

        $a = $this->service->request($p, $inv, $this->input('600'), $actor);
        try {
            $this->service->request($p, $inv, $this->input('500'), $actor);
            self::fail('600 is already reserved; only 400 is left');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
        $this->service->request($p, $inv, $this->input('400'), $actor); // exactly the rest

        try {
            $this->service->request($p, $inv, $this->input('0.01'), $actor);
            self::fail('nothing left');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        // a rejected refund gives its share back
        $this->service->reject($a, 'not eligible', $this->actor('manager'), $a->recordVersion);
        self::assertSame('pending', $this->service->request($p, $inv, $this->input('600'), $actor)->status);
    }

    public function test_credit_refunds_reserve_unallocated_credit_and_shrink_what_can_be_allocated(): void
    {
        $actor = $this->actor();
        [$inv, $p] = $this->paidInvoice($actor, '1500'); // 500 credit
        self::assertSame('500.00', $p->unallocated());

        $r = $this->service->request($p, null, $this->input('200'), $actor);

        self::assertNull($r->invoiceId);
        self::assertSame('300.00', $this->freshPayment($p)->unallocated(), 'a pending credit refund is reserved at once');
        try {
            $this->service->request($p, null, $this->input('400'), $actor);
            self::fail('only 300 of credit is left');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        // credit cannot be refunded through an invoice it was not applied to, and vice versa
        try {
            $this->service->request($p, $inv, $this->input('1200'), $actor);
            self::fail('the invoice only received 1000');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
    }

    public function test_request_rules_and_validator(): void
    {
        $actor = $this->actor();
        [$inv, $p] = $this->paidInvoice($actor);
        $reversed = $this->payments->reverse($this->pay($actor, $this->issued($actor, $this->application($actor)), '100'), 'test', $actor, 1);

        try {
            $this->service->request($reversed, null, $this->input('10'), $actor);
            self::fail('a reversed payment cannot be refunded');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $v = new RefundValidator();
        foreach ([
            'zero'             => ['amount' => '0', 'method' => 'cash', 'reason' => 'Because'],
            'negative'         => ['amount' => '-1', 'method' => 'cash', 'reason' => 'Because'],
            'text'             => ['amount' => 'ten', 'method' => 'cash', 'reason' => 'Because'],
            'unknown method'   => ['amount' => '10', 'method' => 'barter', 'reason' => 'Because'],
            'no reason'        => ['amount' => '10', 'method' => 'cash', 'reason' => ''],
            'tiny reason'      => ['amount' => '10', 'method' => 'cash', 'reason' => 'x'],
            'reason too long'  => ['amount' => '10', 'method' => 'cash', 'reason' => str_repeat('r', 256)],
        ] as $why => $data) {
            try {
                $v->request($data);
                self::fail("accepted: {$why}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('adjustment', $v->request(['amount' => '5', 'method' => 'adjustment', 'reason' => 'Goodwill'])['method']);
        self::assertSame('Duplicate', $v->reason(['reason' => ' Duplicate ']));
    }

    // ---- approval flow ---------------------------------------------

    public function test_the_requester_cannot_approve_their_own_refund_but_someone_else_can(): void
    {
        $manager = $this->actor('manager');
        $other = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($manager);
        $r = $this->service->request($p, $inv, $this->input('100'), $manager);

        try {
            $this->service->approve($r, $manager, $r->recordVersion);
            self::fail('separation of duties');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $approved = $this->service->approve($r, $other, $r->recordVersion);
        self::assertSame('approved', $approved->status);
        self::assertSame($other->name, $approved->approvedBy);
        self::assertNotNull($approved->approvedAt);
        self::assertSame(['pending', 'approved'], $this->trail($approved));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'refund_approved'", [$manager->id]), 'the requester is told');
    }

    public function test_self_approval_can_be_switched_on_for_a_one_person_office(): void
    {
        $manager = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($manager);
        $r = $this->service->request($p, $inv, $this->input('100'), $manager);

        $lenient = new RefundService(
            $this->db, $this->repo, $this->app->get(RefundHistoryRepository::class), $this->app->get(PaymentRepository::class),
            $this->app->get(\App\Repositories\PaymentAllocationRepository::class), $this->invoices, $this->app->get(InvoiceHistoryRepository::class),
            $this->app->get(UserRepository::class), $this->app->get(\App\Domain\StatusMachine::class), $this->app->get(\App\Support\Sequences::class),
            $this->app->get(\App\Auth\Gate::class), $this->app->get(\App\Audit\AuditService::class), $this->app->get(BranchScopeResolver::class),
            $this->app->get(\App\Notifications\NotificationService::class), true,
        );

        self::assertSame('approved', $lenient->approve($r, $manager, $r->recordVersion)->status);
    }

    public function test_paying_a_refund_reopens_the_invoice(): void
    {
        $accounts = $this->actor('accounts');
        $manager = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($accounts);
        self::assertSame('paid', $this->fresh($inv)->status);

        $r = $this->service->request($p, $inv, $this->input('400', ['method' => 'upi']), $accounts);
        try {
            $this->service->markPaid($r, $accounts, $r->recordVersion);
            self::fail('must be approved first');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $r = $this->service->approve($r, $manager, $r->recordVersion);
        $r = $this->service->markPaid($r, $accounts, $r->recordVersion);

        self::assertSame('paid', $r->status);
        self::assertNotNull($r->refundedAt);
        $i = $this->fresh($inv);
        self::assertSame('400.00', $i->amountRefunded);
        self::assertSame('partially_paid', $i->status, 'paid back down to partially paid');
        self::assertSame('400.00', $i->outstanding(), 'the refunded amount is owed again');
        self::assertSame(['draft', 'issued', 'paid', 'partially_paid'], array_reverse(array_map(static fn (array $h): string => $h['to'], $this->app->get(InvoiceHistoryRepository::class)->forInvoice($inv->id))));
        self::assertSame(['pending', 'approved', 'paid'], $this->trail($r));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'refund_approved'", [$accounts->id]), 'the requester was told when it was approved');
    }

    public function test_refunding_everything_returns_the_invoice_to_issued(): void
    {
        $actor = $this->actor('manager');
        $approver = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($actor);

        $r = $this->service->request($p, $inv, $this->input('1000'), $actor);
        $r = $this->service->markPaid($this->service->approve($r, $approver, $r->recordVersion), $actor, 2);

        $i = $this->fresh($inv);
        self::assertSame('issued', $i->status);
        self::assertSame('1000.00', $i->outstanding());
        self::assertSame('1000.00', $i->amountPaid, 'the ledger keeps what was received; the refund is recorded beside it');

        // and the customer can pay again
        $this->pay($actor, $this->fresh($inv), '1000');
        self::assertSame('paid', $this->fresh($inv)->status);
    }

    public function test_a_credit_refund_does_not_touch_any_invoice(): void
    {
        $actor = $this->actor('manager');
        $approver = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($actor, '1400');

        $r = $this->service->request($p, null, $this->input('300', ['method' => 'cash']), $actor);
        $this->service->markPaid($this->service->approve($r, $approver, $r->recordVersion), $actor, 2);

        $i = $this->fresh($inv);
        self::assertSame('paid', $i->status);
        self::assertSame('0.00', $i->amountRefunded);
        self::assertSame('100.00', $this->freshPayment($p)->unallocated(), '400 credit − 300 refunded');
    }

    public function test_rejection_needs_a_reason_and_is_final(): void
    {
        $actor = $this->actor('accounts');
        $manager = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($actor);
        $r = $this->service->request($p, $inv, $this->input('100'), $actor);

        try {
            $this->service->reject($r, '  ', $manager, $r->recordVersion);
            self::fail('reason required');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $approved = $this->service->approve($r, $manager, $r->recordVersion);
        $rejected = $this->service->reject($approved, 'Cash drawer is empty', $manager, $approved->recordVersion);
        self::assertSame('rejected', $rejected->status);
        self::assertSame('Cash drawer is empty', $this->app->get(RefundHistoryRepository::class)->forRefund($r->id)[0]['reason']);
        self::assertSame('paid', $this->fresh($inv)->status, 'a rejected refund changes no money');

        foreach ([
            fn () => $this->service->approve($rejected, $manager, $rejected->recordVersion),
            fn () => $this->service->markPaid($rejected, $manager, $rejected->recordVersion),
            fn () => $this->service->reject($rejected, 'again', $manager, $rejected->recordVersion),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('rejected is final');
            } catch (DomainRuleException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_stale_versions_are_refused(): void
    {
        $actor = $this->actor('accounts');
        $manager = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($actor);
        $r = $this->service->request($p, $inv, $this->input('100'), $actor);
        $this->service->approve($r, $manager, $r->recordVersion);

        $this->expectException(StaleRecordException::class);
        $this->service->reject($r, 'late', $manager, $r->recordVersion);
    }

    public function test_an_open_refund_blocks_reversing_the_payment_until_it_is_rejected(): void
    {
        $actor = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($actor);
        $r = $this->service->request($p, $inv, $this->input('100'), $actor);

        try {
            $this->payments->reverse($p, 'try', $actor, $p->recordVersion);
            self::fail('blocked by the pending refund');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $this->service->reject($r, 'changed my mind', $this->actor('manager'), $r->recordVersion);
        self::assertSame('reversed', $this->payments->reverse($p, 'now fine', $actor, $p->recordVersion)->status);
    }

    // ---- permissions -----------------------------------------------

    public function test_permissions_and_branch_scope(): void
    {
        $manager = $this->actor('manager');
        $accounts = $this->actor('accounts');
        [$inv, $p] = $this->paidInvoice($manager);
        $r = $this->service->request($p, $inv, $this->input('100'), $accounts);

        foreach (['read_only', 'counselor'] as $role) {
            $denied = $this->actor($role);
            foreach ([
                fn () => $this->service->request($p, $inv, $this->input('10'), $denied),
                fn () => $this->service->approve($r, $denied, $r->recordVersion),
                fn () => $this->service->reject($r, 'x', $denied, $r->recordVersion),
                fn () => $this->service->markPaid($r, $denied, $r->recordVersion),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail("{$role} must not handle refunds");
                } catch (AuthorizationException) {
                    self::assertTrue(true);
                }
            }
        }

        // accounts may request and pay out, but approval is the manager's
        try {
            $this->service->approve($r, $accounts, $r->recordVersion);
            self::fail('accounts does not hold refunds.approve');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $elsewhere = $this->actor('manager', $this->branchB);
        self::assertNull($this->repo->findByPublicId($r->publicId, $this->scope($elsewhere)));
        try {
            $this->service->approve($r, $elsewhere, $r->recordVersion);
            self::fail('outside the branch');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        try {
            $this->service->request($p, $inv, $this->input('10'), $elsewhere);
            self::fail('outside the branch');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    // ---- register --------------------------------------------------

    public function test_register_search_filters_and_counts(): void
    {
        $actor = $this->actor('manager');
        $approver = $this->actor('manager');
        [$inv, $p] = $this->paidInvoice($actor);
        $a = $this->service->request($p, $inv, $this->input('100'), $actor);
        $b = $this->service->request($p, $inv, $this->input('200'), $actor);
        $this->service->approve($b, $approver, $b->recordVersion);
        $scope = $this->scope();

        $total = fn (array $q): int => $this->repo->paginate(ListQuery::of($q), $scope)->total;
        self::assertSame(2, $total([]));
        self::assertSame(1, $total(['search' => $a->refundNumber]));
        self::assertSame(2, $total(['search' => $p->paymentNumber]));
        self::assertSame(2, $total(['search' => 'RFX Cand']));
        self::assertSame(0, $total(['search' => 'nobody-at-all']));
        self::assertSame(1, $total(['filters' => ['status' => 'approved']]));
        self::assertSame(1, $total(['filters' => ['status' => 'pending']]));

        $counts = $this->repo->statusCounts($scope);
        self::assertSame(['pending', 'approved', 'paid', 'rejected'], array_keys($counts));
        self::assertSame(1, $counts['pending']);
        self::assertCount(2, $this->repo->forPayment($p->id));
        self::assertSame('300.00', $this->repo->activeSum($p->id, $inv->id));
        self::assertSame(0, $this->repo->paginate(ListQuery::of([]), $this->scope($this->actor('manager', $this->branchB)))->total);
    }

    // ---- ageing and reminders --------------------------------------

    /** An issued invoice for its own customer, due `$daysFromToday` days from today (negative = overdue). */
    private function dueIn(User $actor, int $daysFromToday, string $amount = '1000'): Invoice
    {
        $inv = $this->issued($actor, $this->application($actor), $amount);
        $this->db->affectingStatement('UPDATE invoices SET due_on = ? WHERE id = ?', [gmdate('Y-m-d', strtotime(($daysFromToday >= 0 ? '+' : '') . $daysFromToday . ' days')), $inv->id]);

        return $this->fresh($inv);
    }

    public function test_ageing_buckets_by_days_overdue(): void
    {
        $actor = $this->actor('manager');
        $this->dueIn($actor, 10, '100');    // not yet due
        $this->dueIn($actor, -5, '200');    // 1–30
        $this->dueIn($actor, -45, '300');   // 31–60
        $this->dueIn($actor, -75, '400');   // 61–90
        $this->dueIn($actor, -120, '500');  // 90+
        $partly = $this->dueIn($actor, -10, '1000');
        $this->pay($actor, $partly, '400'); // owes 600, 1–30
        $paid = $this->dueIn($actor, -50, '999');
        $this->pay($actor, $paid, '999');   // fully paid: not counted
        $draft = $this->invoiceService->createForApplication($this->application($actor), (new InvoiceValidator())->invoice(['line_description' => ['x'], 'line_quantity' => ['1'], 'line_unit_price' => ['777']]), $actor);

        $rows = $this->invoices->aging($this->scope());
        self::assertCount(1, $rows);
        $a = $rows[0];
        self::assertSame('INR', $a['currency']);
        self::assertSame(6, $a['invoices']);
        self::assertSame('100.00', $a['current']);
        self::assertSame('800.00', $a['d1_30'], '200 + 600 still owed on the part-paid one');
        self::assertSame('300.00', $a['d31_60']);
        self::assertSame('400.00', $a['d61_90']);
        self::assertSame('500.00', $a['d90_plus']);
        self::assertSame('2100.00', $a['total']);
        self::assertNotNull($draft->id);

        $debtors = $this->invoices->topDebtors($this->scope(), 3);
        self::assertCount(3, $debtors);
        self::assertSame(['600.00', '500.00', '400.00'], array_column($debtors, 'outstanding'), 'biggest first');
        self::assertSame([], $this->invoices->aging($this->scope($this->actor('manager', $this->branchB))), 'branch scoping');
    }

    public function test_overdue_reminders_go_to_the_creator_and_accounts_once_a_week(): void
    {
        $manager = $this->actor('manager');
        $accounts = $this->actor('accounts');
        $inv = $this->dueIn($manager, -3);
        $this->dueIn($manager, 5);                         // not due yet
        $paid = $this->dueIn($manager, -9);
        $this->pay($manager, $paid, '1000');               // settled
        $svc = $this->app->get(PaymentReminderService::class);
        $today = gmdate('Y-m-d');

        self::assertSame(2, $svc->remindOverdue($today), 'creator + accounts for the one overdue invoice');
        foreach ([$manager, $accounts] as $u) {
            self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'invoice_overdue'", [$u->id]));
        }
        $n = $this->db->selectOne("SELECT title, link_type, link_id FROM notifications WHERE user_id = ? AND type = 'invoice_overdue'", [$accounts->id]);
        self::assertStringContainsString('overdue 3 days', $n['title']);
        self::assertSame('invoice', $n['link_type']);
        self::assertSame($inv->id, (int) $n['link_id']);

        $svc->remindOverdue($today);
        $svc->remindOverdue($today);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'invoice_overdue'", [$accounts->id]), 're-running the same day adds nothing');

        $forInvoice = fn (): int => (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'invoice_overdue' AND link_id = ?", [$accounts->id, $inv->id]);

        $svc->remindOverdue(gmdate('Y-m-d', strtotime('+3 days')));   // day 6 → still week 0
        self::assertSame(1, $forInvoice());

        $svc->remindOverdue(gmdate('Y-m-d', strtotime('+8 days')));   // day 11 → week 1
        self::assertSame(2, $forInvoice(), 'a new week, a new reminder');
    }

    public function test_an_overdue_invoice_gets_one_collect_payment_task_for_accounts(): void
    {
        $manager = $this->actor('manager');
        $accounts = $this->actor('accounts');
        $inv = $this->dueIn($manager, -10);
        $this->dueIn($manager, 5);                          // not overdue → no task
        $svc = $this->app->get(PaymentReminderService::class);
        $tasks = fn (): array => $this->db->select("SELECT * FROM tasks WHERE related_type = 'invoice' AND related_id = ?", [$inv->id]);

        $svc->remindOverdue(gmdate('Y-m-d'));
        $svc->remindOverdue(gmdate('Y-m-d'));               // idempotent
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM tasks WHERE branch_id = ? AND related_type = 'invoice'", [$this->branchA]), 'nothing for the invoice that is not due');
        $svc->remindOverdue(gmdate('Y-m-d', strtotime('+8 days')));   // pending task already open → still one for this invoice

        $rows = $tasks();
        self::assertCount(1, $rows);
        self::assertSame((int) $accounts->id, (int) $rows[0]['assigned_to'], 'accounts, not the creator');
        self::assertSame($this->branchA, (int) $rows[0]['branch_id']);
        self::assertSame('system', $rows[0]['source']);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame('high', $rows[0]['priority'], '10 days overdue');
        self::assertSame(gmdate('Y-m-d'), $rows[0]['due_date']);
        self::assertStringContainsString($inv->invoiceNumber, $rows[0]['title']);
    }

    public function test_the_task_falls_back_to_the_creator_and_reopens_a_month_later_once_done(): void
    {
        $manager = $this->actor('manager');                 // no accounts user in this branch
        $inv = $this->dueIn($manager, -3);
        $svc = $this->app->get(PaymentReminderService::class);

        $svc->remindOverdue(gmdate('Y-m-d'));
        $first = $this->db->selectOne("SELECT id, assigned_to, priority FROM tasks WHERE related_id = ? AND related_type = 'invoice'", [$inv->id]);
        self::assertSame((int) $manager->id, (int) $first['assigned_to']);
        self::assertSame('medium', $first['priority']);

        $this->db->affectingStatement("UPDATE tasks SET status = 'completed' WHERE id = ?", [$first['id']]);
        $svc->remindOverdue(gmdate('Y-m-d', strtotime('+10 days')));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM tasks WHERE related_id = ? AND related_type = 'invoice'", [$inv->id]), 'completed, same 28-day span → not reopened');

        $svc->remindOverdue(gmdate('Y-m-d', strtotime('+31 days')));   // day 34 → next span
        self::assertSame(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM tasks WHERE related_id = ? AND related_type = 'invoice'", [$inv->id]));
        self::assertSame('urgent', $this->db->selectValue("SELECT priority FROM tasks WHERE related_id = ? AND status = 'pending'", [$inv->id]), '34 days overdue');
    }
}
