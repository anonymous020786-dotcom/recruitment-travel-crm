<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\User;
use App\Repositories\InvoiceRepository;
use App\Repositories\UserRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\InvoiceValidator;
use App\Validators\JobValidator;
use App\Validators\PaymentValidator;
use Tests\Support\DbTestCase;

final class FinanceReportTest extends DbTestCase
{
    private ReportService $reports;
    private InvoiceService $invoiceService;
    private PaymentService $payments;
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
        $this->reports = $this->app->get(ReportService::class);
        $this->invoiceService = $this->app->get(InvoiceService::class);
        $this->payments = $this->app->get(PaymentService::class);
        $this->branchA = $this->branch('FRX-A');
        $this->branchB = $this->branch('FRX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'FRX-%')";
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'FRX-%'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'fr_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function scopeOf(User $u): \App\Auth\BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($u);
    }

    private function application(User $actor, int $branch): Application
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'FRX Cand', 'phone' => '87' . str_pad((string) (random_int(1000, 9999) * 10000 + ++$this->seq), 8, '0', STR_PAD_LEFT), 'priority' => 'medium'], $actor, $branch, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'FRX Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $branch);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);

        return $this->app->get(ApplicationService::class)->create($c, $job, $actor);
    }

    private function issued(User $actor, Application $app, string $amount, string $currency, int $dueInDays): Invoice
    {
        $d = $this->invoiceService->createForApplication($app, (new InvoiceValidator())->invoice([
            'currency' => $currency, 'line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => [$amount],
        ]), $actor);
        $inv = $this->invoiceService->issue($d, $actor, $d->recordVersion);
        $this->db->affectingStatement('UPDATE invoices SET due_on = ? WHERE id = ?', [gmdate('Y-m-d', strtotime(($dueInDays >= 0 ? '+' : '') . $dueInDays . ' days')), $inv->id]);

        return $this->app->get(InvoiceRepository::class)->findById($inv->id, $this->scopeOf($actor));
    }

    private function pay(User $actor, Invoice $inv, string $amount, string $method = 'cash', ?string $ref = null): \App\Models\Payment
    {
        return $this->payments->recordForInvoice($inv, (new PaymentValidator())->payment(['amount' => $amount, 'method' => $method, 'reference' => $ref ?? '']), $actor)['payment'];
    }

    /** @return list<list<string|int>> */
    private function rowsOf(string $key, User $user, array $input = []): array
    {
        return iterator_to_array($this->reports->rows($key, $this->reports->filters($key, $input), $this->scopeOf($user), $user), false);
    }

    private function seed(User $manager): void
    {
        $inv1 = $this->issued($manager, $this->application($manager, $this->branchA), '1000', 'inr', -10);   // owes 600, overdue 10 days
        $this->pay($manager, $inv1, '400', 'upi', 'UTR-FRX-1');
        $cash = $this->pay($manager, $this->app->get(InvoiceRepository::class)->findById($inv1->id, $this->scopeOf($manager)), '100');
        $this->payments->reverse($cash, 'entered twice', $manager, $cash->recordVersion);                   // must not count as money
        $inv2 = $this->issued($manager, $this->application($manager, $this->branchA), '500', 'aed', 5);
        $card = $this->pay($manager, $inv2, '500', 'card', 'TXN-FRX-2');
        $this->db->insertRow('refunds', [
            'public_id' => Ulid::generate(), 'refund_number' => 'RF-FRX-' . bin2hex(random_bytes(3)), 'payment_id' => $card->id, 'invoice_id' => $inv2->id,
            'person_id' => $card->personId, 'branch_id' => $this->branchA, 'amount' => '50.00', 'currency' => 'AED', 'method' => 'cash', 'reason' => 'Goodwill', 'status' => 'pending', 'created_by' => $manager->id,
        ]);
    }

    // ---- tests -----------------------------------------------------

    public function test_finance_reports_need_the_finance_permission(): void
    {
        $keys = ['collections', 'payments-register', 'invoices-register', 'refunds-register', 'overdue-invoices'];

        foreach (['manager', 'accounts'] as $role) {
            $u = $this->actor($role);
            foreach ($keys as $k) {
                self::assertNotNull($this->reports->definition($k, $u), "{$role} may run {$k}");
            }
            self::assertArrayHasKey('Finance', $this->reports->catalogFor($u));
        }

        foreach (['read_only', 'counselor'] as $role) {
            $u = $this->actor($role);
            foreach ($keys as $k) {
                self::assertNull($this->reports->definition($k, $u), "{$role} must not run {$k}");
            }
            self::assertArrayNotHasKey('Finance', $this->reports->catalogFor($u));
        }
    }

    public function test_collections_counts_only_recorded_money_per_month_currency_and_method(): void
    {
        $manager = $this->actor('manager');
        $this->seed($manager);
        $month = gmdate('Y-m');

        self::assertSame([
            [$month, 'AED', 'Card', 1, '500.00'],
            [$month, 'INR', 'Upi', 1, '400.00'],
        ], $this->rowsOf('collections', $manager), 'the reversed cash payment is not money; nothing is added across currencies');

        self::assertSame([], $this->rowsOf('collections', $manager, ['from' => '2020-01-01', 'to' => '2020-12-31']));
    }

    public function test_the_registers_list_every_record_with_correct_money(): void
    {
        $manager = $this->actor('manager');
        $this->seed($manager);

        $pay = $this->rowsOf('payments-register', $manager);
        self::assertCount(3, $pay, 'the reversed payment is still in the register, flagged');
        $byMethod = [];
        foreach ($pay as $r) {
            $byMethod[$r[4]] = $r;
        }
        self::assertSame('UTR-FRX-1', $byMethod['Upi'][5]);
        self::assertSame(['INR', '400.00', '400.00', 'Recorded'], array_slice($byMethod['Upi'], 6));
        self::assertSame('Reversed', $byMethod['Cash'][9]);
        self::assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $byMethod['Card'][1]);
        self::assertMatchesRegularExpression('/^RCT-\d{4}-\d{6}$/', $byMethod['Card'][2]);

        $inv = [];
        foreach ($this->rowsOf('invoices-register', $manager) as $r) {
            $inv[$r[6]] = $r;
        }
        self::assertCount(2, $inv);
        self::assertSame(['1000.00', '400.00', '0.00', '600.00', 'Partially Paid'], array_merge(array_slice($inv['INR'], 7, 4), [$inv['INR'][11]]));
        self::assertSame(['500.00', '500.00', '0.00', '0.00', 'Paid'], array_merge(array_slice($inv['AED'], 7, 4), [$inv['AED'][11]]));
        self::assertSame('Recruitment', $inv['INR'][2]);
        self::assertStringStartsWith('APP-', $inv['INR'][3]);

        $refunds = $this->rowsOf('refunds-register', $manager);
        self::assertCount(1, $refunds);
        self::assertSame(['AED', '50.00', 'Cash', 'Pending'], array_slice($refunds[0], 5, 4));
        self::assertSame('Goodwill', $refunds[0][11]);
        self::assertMatchesRegularExpression('/^INV-/', $refunds[0][4]);
    }

    public function test_the_overdue_list_is_a_live_snapshot_with_no_date_filter(): void
    {
        $manager = $this->actor('manager');
        $this->seed($manager);

        $rows = $this->rowsOf('overdue-invoices', $manager, ['from' => 'garbage', 'to' => 'nonsense']);
        self::assertCount(1, $rows, 'only the INR invoice is past due; the AED one is paid');
        self::assertSame(10, $rows[0][4]);
        self::assertSame(['INR', '600.00', 'Partially Paid'], array_slice($rows[0], 5, 3));
        self::assertMatchesRegularExpression('/^\d{7,}$/', $rows[0][2], 'the phone is there for chasing');
    }

    public function test_finance_reports_are_branch_scoped_and_export_cleanly(): void
    {
        $manager = $this->actor('manager');
        $this->seed($manager);
        $other = $this->actor('manager', $this->branchB);
        $invB = $this->issued($other, $this->application($other, $this->branchB), '9999', 'inr', -40);
        $this->pay($other, $invB, '1000');

        foreach (['collections', 'payments-register', 'invoices-register', 'overdue-invoices'] as $k) {
            foreach ($this->rowsOf($k, $manager) as $row) {
                self::assertNotContains('9999.00', array_map('strval', $row), "{$k}: branch B must not leak into branch A");
            }
        }
        self::assertCount(1, $this->rowsOf('collections', $other), 'branch B sees only its own collection');
        self::assertSame([], $this->rowsOf('refunds-register', $other));

        // CSV of a register: header + every row, money as plain 2-dp strings
        $h = fopen('php://temp', 'w+b');
        $n = $this->reports->exportCsv('invoices-register', $this->reports->filters('invoices-register', []), $this->scopeOf($manager), $manager, $h);
        rewind($h);
        $lines = [];
        while (($row = fgetcsv($h)) !== false) {
            $lines[] = $row;
        }
        fclose($h);
        self::assertSame(2, $n);
        self::assertSame('Invoice', $lines[0][0]);
        self::assertSame('Outstanding', $lines[0][10]);
        self::assertCount(3, $lines);
        self::assertContains('600.00', array_column($lines, 10));
    }
}
