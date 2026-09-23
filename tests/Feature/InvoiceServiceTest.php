<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Invoice;
use App\Models\TourBooking;
use App\Models\User;
use App\Repositories\InvoiceHistoryRepository;
use App\Repositories\InvoiceLineRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\UserRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\TourBookingService;
use App\Support\Hash;
use App\Support\ListQuery;
use App\Support\Ulid;
use App\Validators\InvoiceValidator;
use App\Validators\JobValidator;
use App\Validators\TourBookingValidator;
use Tests\Support\DbTestCase;

final class InvoiceServiceTest extends DbTestCase
{
    private InvoiceService $service;
    private InvoiceRepository $repo;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(InvoiceService::class);
        $this->repo = $this->app->get(InvoiceRepository::class);
        $this->branchA = $this->branch('IVX-A');
        $this->branchB = $this->branch('IVX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'IVX-%')";
        $this->db->affectingStatement("DELETE FROM invoices WHERE {$like}"); // lines + history cascade
        $this->db->affectingStatement("DELETE FROM tour_bookings WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('invoices', 'tours', 'applications', 'jobs', 'employers', 'leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM persons WHERE full_name LIKE 'IVX %'");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'IVX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(invoice|tour_booking|job|employer|lead|candidate|application):'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'iv_' . bin2hex(random_bytes(4)) . '@dev.local',
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
        $lead = $leads->create(['name' => 'IVX Cand', 'phone' => '93' . random_int(10000000, 99999999), 'priority' => 'medium'], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'IVX Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);

        return $this->app->get(ApplicationService::class)->create($c, $job, $actor);
    }

    private function booking(User $actor, string $status = 'inquiry'): TourBooking
    {
        $v = new TourBookingValidator();
        $b = $this->app->get(TourBookingService::class)->create(
            $v->customer(['customer_name' => 'IVX Traveller', 'customer_phone' => '92' . str_pad((string) (random_int(1000, 9999) * 10000 + ++$this->seq), 8, '0', STR_PAD_LEFT)]),
            $v->trip(['adults' => '2', 'total_amount' => '80000', 'currency' => 'aed']),
            $actor,
        );
        if ($status === 'cancelled') {
            $b = $this->app->get(TourBookingService::class)->changeStatus($b, 'cancelled', $actor, $b->recordVersion, 'test');
        }

        return $b;
    }

    /** @param array<string,mixed> $extra */
    private function data(array $extra = []): array
    {
        return (new InvoiceValidator())->invoice($extra + [
            'line_description' => ['Service fee', 'Documentation'], 'line_quantity' => ['1', '2'], 'line_unit_price' => ['50000', '1500.50'],
        ]);
    }

    private function draft(User $actor, array $extra = []): Invoice
    {
        return $this->service->createForApplication($this->application($actor), $this->data($extra), $actor);
    }

    private function fresh(Invoice $i): Invoice
    {
        return $this->repo->findById($i->id, $this->scope());
    }

    private function statusTrail(Invoice $i): array
    {
        return array_reverse(array_map(static fn (array $h): string => $h['to'], $this->app->get(InvoiceHistoryRepository::class)->forInvoice($i->id)));
    }

    // ---- create ----------------------------------------------------

    public function test_a_draft_for_an_application_derives_its_totals_from_the_lines(): void
    {
        $actor = $this->actor();
        $app = $this->application($actor);
        $inv = $this->service->createForApplication($app, $this->data(['discount_total' => '1000', 'tax_total' => '2500.25']), $actor);

        self::assertSame('draft', $inv->status);
        self::assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $inv->invoiceNumber);
        self::assertSame('application', $inv->type);
        self::assertSame($app->applicationNumber, $inv->referenceNumber);
        self::assertSame('INR', $inv->currency);
        self::assertSame('53001.00', $inv->subtotal, '50,000 + 2 × 1,500.50');
        self::assertSame('1000.00', $inv->discountTotal);
        self::assertSame('2500.25', $inv->taxTotal);
        self::assertSame('54501.25', $inv->grandTotal, '53,001.00 − 1,000.00 + 2,500.25');
        self::assertSame('0.00', $inv->amountPaid);
        self::assertSame($this->branchA, $inv->branchId);
        self::assertSame((int) $this->db->selectValue('SELECT person_id FROM candidates WHERE id = ?', [$app->candidateId]), $inv->personId, 'billed to the candidate\'s person');

        $lines = $this->app->get(InvoiceLineRepository::class)->forInvoice($inv->id);
        self::assertCount(2, $lines);
        self::assertSame('3001.00', $lines[1]['line_total']);
        self::assertSame(['draft'], $this->statusTrail($inv));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='invoices' AND action='created' AND record_id = ?", [$inv->id]));
    }

    public function test_line_totals_round_half_up_per_line(): void
    {
        $actor = $this->actor();
        $inv = $this->service->createForApplication($this->application($actor), $this->data([
            'line_description' => ['A', 'B'], 'line_quantity' => ['3', '0.5'], 'line_unit_price' => ['33.33', '19.99'],
        ]), $actor);

        self::assertSame('109.99', $inv->subtotal, '99.99 + 10.00');
    }

    public function test_a_tour_booking_invoice_uses_the_booking_person_and_currency(): void
    {
        $actor = $this->actor();
        $b = $this->booking($actor);
        $inv = $this->service->createForTourBooking($b, $this->data(), $actor);

        self::assertSame('tour_booking', $inv->type);
        self::assertSame($b->personId, $inv->personId);
        self::assertSame('AED', $inv->currency, 'defaults to the booking currency');
        self::assertSame($b->bookingNumber, $inv->referenceNumber);

        $explicit = $this->service->createForTourBooking($b, $this->data(['currency' => 'inr']), $actor);
        self::assertSame('INR', $explicit->currency);
        self::assertNotSame($inv->invoiceNumber, $explicit->invoiceNumber);
        self::assertCount(2, $this->repo->forInvoiceable('tour_booking', $b->id, $this->scope()));
    }

    public function test_closed_targets_cannot_be_invoiced(): void
    {
        $actor = $this->actor();
        $cancelled = $this->booking($actor, 'cancelled');
        try {
            $this->service->createForTourBooking($cancelled, $this->data(), $actor);
            self::fail('cancelled booking');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $app = $this->application($actor);
        $app = $this->app->get(ApplicationService::class)->changeStatus($app, 'cancelled', $actor, $app->recordVersion, 'Withdrew');
        $this->expectException(DomainRuleException::class);
        $this->service->createForApplication($app, $this->data(), $actor);
    }

    public function test_a_draft_may_have_no_lines_yet(): void
    {
        $actor = $this->actor();
        $inv = $this->service->createForApplication($this->application($actor), (new InvoiceValidator())->invoice([]), $actor);

        self::assertSame('0.00', $inv->grandTotal);
        self::assertSame([], $this->app->get(InvoiceLineRepository::class)->forInvoice($inv->id));
    }

    public function test_validator_rules(): void
    {
        $v = new InvoiceValidator();

        $ok = $v->invoice(['line_description' => ['Fee', '', 'Ticket'], 'line_quantity' => ['1', '1', ''], 'line_unit_price' => ['100', '', '250'], 'currency' => 'aed']);
        self::assertCount(2, $ok['lines'], 'the untouched row is ignored');
        self::assertSame('1.00', $ok['lines'][1]['quantity'], 'a blank quantity defaults to 1');
        self::assertSame('AED', $ok['currency']);

        $bad = [
            'price without description' => ['line_description' => [''], 'line_quantity' => ['1'], 'line_unit_price' => ['100']],
            'description without price' => ['line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => ['']],
            'zero quantity'             => ['line_description' => ['Fee'], 'line_quantity' => ['0'], 'line_unit_price' => ['1']],
            'huge quantity'             => ['line_description' => ['Fee'], 'line_quantity' => ['999999'], 'line_unit_price' => ['1']],
            'negative price'            => ['line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => ['-1']],
            'huge price'                => ['line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => ['999999999']],
            'text price'                => ['line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => ['ten']],
            'bad currency'              => ['currency' => 'RUPEES'],
            'bad due date'              => ['due_on' => '31/12/2030'],
            'negative discount'         => ['discount_total' => '-5'],
            'notes too long'            => ['notes' => str_repeat('x', 501)],
            'too many lines'            => ['line_description' => array_fill(0, 41, 'L'), 'line_quantity' => array_fill(0, 41, '1'), 'line_unit_price' => array_fill(0, 41, '1')],
        ];
        foreach ($bad as $why => $input) {
            try {
                $v->invoice($input);
                self::fail("accepted: {$why}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }

        $t = $v->target(['type' => 'tour_booking', 'reference' => ' tb-2026-000001 ']);
        self::assertSame(['type' => 'tour_booking', 'reference' => 'TB-2026-000001'], $t);
        $this->expectException(ValidationException::class);
        $v->target(['type' => 'refund', 'reference' => 'X-1']);
    }

    public function test_a_discount_cannot_exceed_the_subtotal(): void
    {
        $actor = $this->actor();
        $this->expectException(ValidationException::class);
        $this->service->createForApplication($this->application($actor), $this->data(['discount_total' => '99999']), $actor);
    }

    // ---- edit / issue / void ---------------------------------------

    public function test_update_replaces_the_lines_of_a_draft_and_recomputes(): void
    {
        $actor = $this->actor();
        $inv = $this->draft($actor);

        $inv = $this->service->update($inv, $this->data(['line_description' => ['Only line'], 'line_quantity' => ['4'], 'line_unit_price' => ['250'], 'due_on' => '2031-01-31', 'notes' => 'Revised']), $actor, $inv->recordVersion);

        self::assertSame('1000.00', $inv->grandTotal);
        self::assertSame('2031-01-31', $inv->dueOn);
        self::assertSame('Revised', $inv->notes);
        self::assertSame(2, $inv->recordVersion);
        self::assertCount(1, $this->app->get(InvoiceLineRepository::class)->forInvoice($inv->id));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='invoices' AND action='updated' AND record_id = ?", [$inv->id]));
    }

    public function test_a_stale_edit_is_refused_and_changes_nothing(): void
    {
        $actor = $this->actor();
        $inv = $this->draft($actor);
        $this->service->update($inv, $this->data(['notes' => 'first']), $actor, $inv->recordVersion);

        try {
            $this->service->update($inv, $this->data(['notes' => 'second', 'line_description' => ['Other'], 'line_quantity' => ['1'], 'line_unit_price' => ['1']]), $actor, $inv->recordVersion);
            self::fail('stale');
        } catch (StaleRecordException) {
            self::assertTrue(true);
        }
        self::assertSame('first', $this->fresh($inv)->notes);
        self::assertCount(2, $this->app->get(InvoiceLineRepository::class)->forInvoice($inv->id), 'the lines were not touched either');
    }

    public function test_issuing_freezes_the_invoice_and_sets_dates(): void
    {
        $actor = $this->actor();
        $inv = $this->draft($actor);

        $issued = $this->service->issue($inv, $actor, $inv->recordVersion);

        self::assertSame('issued', $issued->status);
        self::assertSame(gmdate('Y-m-d'), $issued->issuedOn);
        self::assertSame(gmdate('Y-m-d', strtotime('+' . InvoiceService::DEFAULT_TERMS_DAYS . ' days')), $issued->dueOn);
        self::assertSame($issued->grandTotal, $issued->outstanding());
        self::assertSame(['draft', 'issued'], $this->statusTrail($issued));

        foreach ([
            fn () => $this->service->update($issued, $this->data(), $actor, $issued->recordVersion),
            fn () => $this->service->issue($issued, $actor, $issued->recordVersion),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('an issued invoice is frozen');
            } catch (DomainRuleException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_issue_needs_lines_a_positive_total_and_a_sane_due_date(): void
    {
        $actor = $this->actor();

        $empty = $this->service->createForApplication($this->application($actor), (new InvoiceValidator())->invoice([]), $actor);
        try {
            $this->service->issue($empty, $actor, $empty->recordVersion);
            self::fail('no lines');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $free = $this->service->createForApplication($this->application($actor), $this->data(['line_unit_price' => ['0', '0']]), $actor);
        try {
            $this->service->issue($free, $actor, $free->recordVersion);
            self::fail('zero total');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $inv = $this->draft($actor);
        try {
            $this->service->issue($inv, $actor, $inv->recordVersion, '2020-01-01');
            self::fail('due date in the past');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $custom = gmdate('Y-m-d', strtotime('+40 days'));
        self::assertSame($custom, $this->service->issue($inv, $actor, $inv->recordVersion, $custom)->dueOn);
    }

    public function test_voiding_needs_a_reason_and_is_final(): void
    {
        $actor = $this->actor();
        $inv = $this->draft($actor);

        try {
            $this->service->void($inv, '  ', $actor, $inv->recordVersion);
            self::fail('reason required');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $void = $this->service->void($inv, 'Raised against the wrong application', $actor, $inv->recordVersion);
        self::assertSame('void', $void->status);
        $history = $this->app->get(InvoiceHistoryRepository::class)->forInvoice($inv->id);
        self::assertSame('Raised against the wrong application', $history[0]['reason']);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='invoices' AND action='voided' AND record_id = ?", [$inv->id]));

        $this->expectException(DomainRuleException::class);
        $this->service->issue($void, $actor, $void->recordVersion);
    }

    public function test_an_invoice_that_has_received_money_cannot_be_voided(): void
    {
        $actor = $this->actor();
        $inv = $this->service->issue($this->draft($actor), $actor, 1);
        $this->db->affectingStatement("UPDATE invoices SET status = 'partially_paid', amount_paid = 100.00 WHERE id = ?", [$inv->id]);
        $inv = $this->fresh($inv);

        $this->expectException(DomainRuleException::class);
        $this->service->void($inv, 'oops', $actor, $inv->recordVersion);
    }

    // ---- model maths -----------------------------------------------

    public function test_outstanding_and_overdue(): void
    {
        $actor = $this->actor();
        $inv = $this->service->issue($this->draft($actor), $actor, 1); // grand 53,001.00
        self::assertFalse($inv->isOverdue());

        $this->db->affectingStatement("UPDATE invoices SET status = 'partially_paid', amount_paid = 20000.00, amount_refunded = 500.00, due_on = ? WHERE id = ?", [gmdate('Y-m-d', strtotime('-3 days')), $inv->id]);
        $inv = $this->fresh($inv);

        self::assertSame('33501.00', $inv->outstanding(), '53,001 − (20,000 − 500)');
        self::assertTrue($inv->isOverdue());

        $this->db->affectingStatement("UPDATE invoices SET status = 'paid', amount_paid = 53001.00, amount_refunded = 0 WHERE id = ?", [$inv->id]);
        $paid = $this->fresh($inv);
        self::assertSame('0.00', $paid->outstanding());
        self::assertFalse($paid->isOverdue(), 'a paid invoice is never overdue');
    }

    // ---- permissions -----------------------------------------------

    public function test_permissions_and_branch_scope(): void
    {
        $manager = $this->actor();
        $app = $this->application($manager);
        $inv = $this->service->createForApplication($app, $this->data(), $manager);

        foreach (['read_only', 'counselor'] as $role) {
            $denied = $this->actor($role);
            foreach ([
                fn () => $this->service->createForApplication($app, $this->data(), $denied),
                fn () => $this->service->update($inv, $this->data(), $denied, $inv->recordVersion),
                fn () => $this->service->issue($inv, $denied, $inv->recordVersion),
                fn () => $this->service->void($inv, 'x', $denied, $inv->recordVersion),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail("{$role} must not manage invoices");
                } catch (AuthorizationException) {
                    self::assertTrue(true);
                }
            }
        }

        $elsewhere = $this->actor('accounts', $this->branchB);
        self::assertNull($this->repo->findByPublicId($inv->publicId, $this->scope($elsewhere)), 'another branch cannot load it');
        try {
            $this->service->issue($inv, $elsewhere, $inv->recordVersion);
            self::fail('outside the branch');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        try {
            $this->service->createForApplication($app, $this->data(), $elsewhere);
            self::fail('cannot invoice another branch\'s application');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        // the accounts role runs invoicing end to end in its branch
        $accounts = $this->actor('accounts');
        $mine = $this->service->createForApplication($this->application($manager), $this->data(), $accounts);
        $mine = $this->service->update($mine, $this->data(['notes' => 'ok']), $accounts, $mine->recordVersion);
        self::assertSame('issued', $this->service->issue($mine, $accounts, $mine->recordVersion)->status);
    }

    // ---- register --------------------------------------------------

    public function test_register_search_filters_summary_and_lookups(): void
    {
        $actor = $this->actor();
        $a = $this->service->issue($this->draft($actor), $actor, 1);                                   // INR 53,001.00 issued
        $b = $this->service->createForTourBooking($this->booking($actor), $this->data(), $actor);        // AED draft
        $c = $this->service->issue($this->service->createForTourBooking($this->booking($actor), $this->data(['line_unit_price' => ['1000', '1000']]), $actor), $actor, 1);
        $this->db->affectingStatement('UPDATE invoices SET due_on = ? WHERE id = ?', [gmdate('Y-m-d', strtotime('-5 days')), $c->id]); // overdue AED
        $scope = $this->scope();

        $total = fn (array $q): int => $this->repo->paginate(ListQuery::of($q), $scope)->total;
        self::assertSame(3, $total([]));
        self::assertSame(1, $total(['search' => $a->invoiceNumber]), 'by number');
        self::assertSame(2, $total(['search' => 'IVX Traveller']), 'by customer');
        self::assertSame(1, $total(['search' => 'IVX Cand']), 'by candidate name');
        self::assertSame(0, $total(['search' => 'nobody-at-all']));
        self::assertSame(1, $total(['filters' => ['status' => 'draft']]));
        self::assertSame(2, $total(['filters' => ['status' => 'issued']]));
        self::assertSame(2, $total(['filters' => ['type' => 'tour_booking']]));
        self::assertSame(1, $total(['filters' => ['due' => 'overdue']]));

        $summary = [];
        foreach ($this->repo->summary($scope) as $s) {
            $summary[$s['currency']] = $s;
        }
        self::assertSame('53001.00', $summary['INR']['billed']);
        self::assertSame('53001.00', $summary['INR']['outstanding']);
        self::assertSame('0.00', $summary['INR']['overdue']);
        self::assertSame('3000.00', $summary['AED']['billed'], 'the draft is not counted (1,000 + 2 × 1,000)');
        self::assertSame('3000.00', $summary['AED']['overdue']);

        self::assertContains($b->id, array_map(static fn (Invoice $i): int => $i->id, $this->repo->forPerson($b->personId, $scope)));
        self::assertSame([], $this->repo->forPerson($b->personId, $this->scope($this->actor('manager', $this->branchB))));
    }
}
