<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScope;
use App\Auth\BranchScopeResolver;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\User;
use App\Repositories\InvoiceRepository;
use App\Repositories\IntegrityRepository;
use App\Repositories\UserRepository;
use App\Services\ApplicationService;
use App\Services\DailyReportService;
use App\Services\DashboardService;
use App\Services\EmployerService;
use App\Services\IntegrityService;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\PaymentService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\InvoiceValidator;
use App\Validators\JobValidator;
use App\Validators\PaymentValidator;
use Tests\Support\DbTestCase;

/** The three Phase 11.2 jobs' services: integrity check, daily report, dashboard warm-up. */
final class AutomationJobsTest extends DbTestCase
{
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
        $this->branchA = $this->branch('AJX-A');
        $this->branchB = $this->branch('AJX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'AJX-%')";
        $this->db->affectingStatement("DELETE FROM settings WHERE key_name LIKE 'dash:%' OR key_name LIKE 'daily-report:%' OR key_name = 'integrity:last'");
        $this->db->affectingStatement("DELETE FROM notifications WHERE type = 'integrity_alert'");
        $this->db->affectingStatement("DELETE FROM email_log WHERE template = 'daily-report'");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'AJX-%'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'aj_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function scopeOf(User $u): BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($u);
    }

    private function application(User $actor): Application
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'AJX Cand', 'phone' => '86' . str_pad((string) (random_int(1000, 9999) * 10000 + ++$this->seq), 8, '0', STR_PAD_LEFT), 'priority' => 'medium'], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'AJX Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);

        return $this->app->get(ApplicationService::class)->create($c, $job, $actor);
    }

    /** A clean ledger: one invoice of 1000 part-paid (400), a second fully paid, one refund paid out. */
    private function ledger(User $manager): array
    {
        $is = $this->app->get(InvoiceService::class);
        $pays = $this->app->get(PaymentService::class);
        $app = $this->application($manager);
        $mk = function (string $amount) use ($is, $manager, $app): Invoice {
            $d = $is->createForApplication($app, (new InvoiceValidator())->invoice(['currency' => 'inr', 'line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => [$amount]]), $manager);

            return $is->issue($d, $manager, $d->recordVersion);
        };
        $inv1 = $mk('1000');
        $p1 = $pays->recordForInvoice($inv1, (new PaymentValidator())->payment(['amount' => '400', 'method' => 'cash']), $manager)['payment'];
        $inv2 = $mk('500');
        $pays->recordForInvoice($inv2, (new PaymentValidator())->payment(['amount' => '500', 'method' => 'cash']), $manager);

        return ['inv1' => $inv1, 'inv2' => $inv2, 'p1' => $p1, 'app' => $app];
    }

    /** Findings whose reference is one of ours (the dev database may hold other rows). */
    private function ours(array $result, array $refs): array
    {
        return array_values(array_filter($result['findings'], static fn (array $f): bool => in_array($f['ref'], $refs, true)));
    }

    // ---- integrity check -------------------------------------------

    public function test_a_clean_ledger_passes_every_check(): void
    {
        $m = $this->actor('manager');
        $l = $this->ledger($m);
        $svc = $this->app->get(IntegrityService::class);

        $r = $svc->run();

        self::assertSame(count(IntegrityService::CHECKS), $r['checked']);
        self::assertSame([], $this->ours($r, [$l['inv1']->invoiceNumber, $l['inv2']->invoiceNumber, $l['p1']->paymentNumber, $l['app']->applicationNumber]));
        self::assertSame($r['at'], $svc->last()['at'], 'the result is stored for the admin screen');
    }

    /** @return array<string,array{0:callable(array):void,1:string,2:string}> */
    public static function corruptions(): array
    {
        return [
            'paid amount drifts from payments' => [fn (\PDO|\App\Support\Db $db, array $l) => $db->affectingStatement('UPDATE invoices SET amount_paid = 999.00 WHERE id = ?', [$l['inv1']->id]), 'invoice_paid', 'inv1'],
            'refunded drifts from paid refunds' => [fn ($db, array $l) => $db->affectingStatement('UPDATE invoices SET amount_refunded = 10.00 WHERE id = ?', [$l['inv1']->id]), 'invoice_refunded', 'inv1'],
            'status contradicts the money'      => [fn ($db, array $l) => $db->affectingStatement("UPDATE invoices SET status = 'paid' WHERE id = ?", [$l['inv1']->id]), 'invoice_status', 'inv1'],
            'grand total contradicts the lines' => [fn ($db, array $l) => $db->affectingStatement('UPDATE invoices SET grand_total = 1234.00, subtotal = 1234.00 WHERE id = ?', [$l['inv1']->id]), 'invoice_totals', 'inv1'],
            'payment over-allocated'            => [fn ($db, array $l) => $db->affectingStatement('UPDATE payment_allocations SET amount = 401.00 WHERE payment_id = ?', [$l['p1']->id]), 'payment_allocations', 'p1'],
            'receipt missing'                   => [fn ($db, array $l) => $db->affectingStatement('DELETE FROM receipts WHERE payment_id = ?', [$l['p1']->id]), 'payment_receipts', 'p1'],
            'invoice points at nothing'         => [fn ($db, array $l) => $db->affectingStatement('UPDATE invoices SET invoiceable_id = 999999999 WHERE id = ?', [$l['inv1']->id]), 'invoice_targets', 'inv1'],
            'application status without history' => [fn ($db, array $l) => $db->affectingStatement("UPDATE applications SET status = 'selected' WHERE id = ?", [$l['app']->id]), 'application_history', 'app'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corruptions')]
    public function test_each_invariant_is_caught_when_it_is_broken(callable $corrupt, string $check, string $who): void
    {
        $m = $this->actor('manager');
        $l = $this->ledger($m);
        $corrupt($this->db, $l);

        $r = $this->app->get(IntegrityService::class)->run();

        $ref = match ($who) { 'p1' => $l['p1']->paymentNumber, 'app' => $l['app']->applicationNumber, default => $l[$who]->invoiceNumber };
        $hits = array_values(array_filter($this->ours($r, [$ref]), static fn (array $f): bool => $f['check'] === $check));
        self::assertNotSame([], $hits, "{$check} should flag {$ref}");
        self::assertNotSame('', $hits[0]['detail']);
        self::assertGreaterThanOrEqual(1, $r['failed']);
    }

    public function test_refunds_exceeding_the_payment_and_lagging_counters_are_caught(): void
    {
        $m = $this->actor('manager');
        $l = $this->ledger($m);

        $this->db->insertRow('refunds', [
            'public_id' => Ulid::generate(), 'refund_number' => 'RF-AJX-' . bin2hex(random_bytes(3)), 'payment_id' => $l['p1']->id, 'invoice_id' => $l['inv1']->id,
            'person_id' => $l['p1']->personId, 'branch_id' => $this->branchA, 'amount' => '500.00', 'currency' => 'INR', 'method' => 'cash', 'reason' => 'x', 'status' => 'pending', 'created_by' => $m->id,
        ]);
        $r = $this->app->get(IntegrityService::class)->run();
        self::assertNotSame([], array_filter($this->ours($r, [$l['p1']->paymentNumber]), static fn (array $f): bool => $f['check'] === 'payment_refunds'), '500 of refunds against a 400 payment');

        $this->db->affectingStatement("UPDATE number_sequences SET next_value = 1 WHERE scope = ?", ['invoice:' . gmdate('Y')]);
        $r = $this->app->get(IntegrityService::class)->run();
        self::assertNotSame([], array_filter($r['findings'], static fn (array $f): bool => $f['check'] === 'sequences' && $f['ref'] === 'invoice:' . gmdate('Y')), 'the counter fell behind issued numbers');
    }

    public function test_failures_alert_super_admins_once_per_check_per_day(): void
    {
        $m = $this->actor('manager');
        $admin = $this->actor('super_admin');
        $l = $this->ledger($m);
        $this->db->affectingStatement('UPDATE invoices SET amount_paid = 999.00 WHERE id = ?', [$l['inv1']->id]);
        $svc = $this->app->get(IntegrityService::class);
        $day = new \DateTimeImmutable('2026-01-15 03:40:00', new \DateTimeZone('UTC'));

        $svc->run($day);
        $svc->run($day);
        $mine = (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'integrity_alert'", [$admin->id]);
        self::assertGreaterThanOrEqual(1, $mine);
        $before = $mine;

        $svc->run($day);
        self::assertSame($before, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'integrity_alert'", [$admin->id]), 're-running the same day adds nothing');

        $svc->run($day->modify('+1 day'));
        self::assertGreaterThan($before, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'integrity_alert'", [$admin->id]), 'still broken tomorrow → a new alert');
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'integrity_alert'", [$m->id]), 'managers are not paged');

        $n = $this->db->selectOne("SELECT title, body FROM notifications WHERE user_id = ? AND type = 'integrity_alert' ORDER BY id LIMIT 1", [$admin->id]);
        self::assertStringStartsWith('Data check failed', $n['title']);
    }

    public function test_the_probes_do_not_mistake_a_draft_or_void_invoice_for_a_problem(): void
    {
        $m = $this->actor('manager');
        $l = $this->ledger($m);
        $is = $this->app->get(InvoiceService::class);
        $d = $is->createForApplication($l['app'], (new InvoiceValidator())->invoice([]), $m);                     // empty draft
        $v = $is->createForApplication($l['app'], (new InvoiceValidator())->invoice(['line_description' => ['x'], 'line_quantity' => ['1'], 'line_unit_price' => ['10']]), $m);
        $is->void($v, 'test', $m, $v->recordVersion);

        $r = $this->app->get(IntegrityService::class)->run();
        self::assertSame([], $this->ours($r, [$d->invoiceNumber, $v->invoiceNumber]));
    }

    // ---- daily report ----------------------------------------------

    public function test_the_daily_report_counts_the_days_activity_for_the_scope(): void
    {
        $m = $this->actor('manager');
        $this->ledger($m);     // 2 leads→candidates, 1 application, 2 invoices, 2 payments
        $svc = $this->app->get(DailyReportService::class);
        $today = gmdate('Y-m-d');

        $a = $svc->build($today, BranchScope::of([$this->branchA]));
        self::assertSame(1, $a['counts']['New leads']);
        self::assertSame(1, $a['counts']['New candidates']);
        self::assertSame(1, $a['counts']['New applications']);
        self::assertSame(0, $a['counts']['Placements']);
        self::assertSame(['INR' => ['count' => 2, 'total' => '1500.00']], $a['money']['Invoices issued']);
        self::assertSame(['INR' => ['count' => 2, 'total' => '900.00']], $a['money']['Payments received']);
        self::assertSame([], $a['money']['Refunds paid out']);
        self::assertFalse($a['quiet']);

        $b = $svc->build($today, BranchScope::of([$this->branchB]));
        self::assertTrue($b['quiet'], 'branch B did nothing');
        self::assertSame(0, array_sum($b['counts']));

        $yesterday = $svc->build(gmdate('Y-m-d', strtotime('-1 day')), BranchScope::of([$this->branchA]));
        self::assertTrue($yesterday['quiet'], 'a different day sees none of it');
    }

    public function test_the_daily_report_is_claimed_once_and_only_active_days_are_emailed(): void
    {
        $manager = $this->actor('manager');
        $idle = $this->actor('manager', $this->branchB);
        $this->ledger($manager);
        $svc = $this->app->get(DailyReportService::class);
        $today = gmdate('Y-m-d');

        $first = $svc->run($today);
        self::assertGreaterThanOrEqual(2, $first['reports'], 'the organisation plus the branches');

        $mailedTo = array_column($this->db->select("SELECT to_email FROM email_log WHERE template = 'daily-report'"), 'to_email');
        self::assertContains($manager->email, $mailedTo, 'the busy branch\'s manager gets the digest');
        self::assertNotContains($idle->email, $mailedTo, 'a quiet branch is stored but not emailed');
        self::assertSame(1, count(array_keys($mailedTo, $manager->email, true)));
        $subject = (string) $this->db->selectValue("SELECT subject FROM email_log WHERE to_email = ? AND template = 'daily-report'", [$manager->email]);
        self::assertStringContainsString($today, $subject);
        self::assertStringContainsString('Branch AJX-A', $subject);

        self::assertNotNull($this->db->selectValue('SELECT value FROM settings WHERE key_name = ?', ["daily-report:{$today}:{$this->branchB}"]), 'quiet reports are still stored');

        $again = $svc->run($today);
        self::assertSame(0, $again['reports'], 'the run-date guard');
        self::assertSame(0, $again['emails']);
        self::assertSame(1, count(array_keys(array_column($this->db->select("SELECT to_email FROM email_log WHERE template = 'daily-report'"), 'to_email'), $manager->email, true)), 'no second email');
    }

    public function test_the_digest_can_be_switched_off_but_is_still_stored(): void
    {
        $manager = $this->actor('manager');
        $this->ledger($manager);
        $off = new DailyReportService(
            $this->app->get(\App\Repositories\DailyReportRepository::class), $this->app->get(UserRepository::class), $this->app->get(\App\Mail\MailComposer::class), false,
        );

        $r = $off->run(gmdate('Y-m-d'));

        self::assertGreaterThanOrEqual(1, $r['reports']);
        self::assertSame(0, $r['emails']);
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM email_log WHERE template = 'daily-report' AND to_email = ?", [$manager->email]));
    }

    // ---- dashboard warm-up -----------------------------------------

    public function test_warming_builds_one_snapshot_per_distinct_scope_and_widget_set(): void
    {
        $a1 = $this->actor('manager');
        $a2 = $this->actor('manager');          // same branch and widgets as $a1
        $b = $this->actor('manager', $this->branchB);
        $ro = $this->actor('read_only');        // same branch, different widgets
        $svc = new DashboardService(
            $this->db, $this->app->get(\App\Repositories\DashboardRepository::class), $this->app->get(\App\Repositories\LeadRepository::class),
            $this->app->get(InvoiceRepository::class), $this->app->get(\App\Auth\PermissionService::class), $this->app->get(\App\Domain\StatusMachine::class), 60,
        );
        $viewers = [[$a1, $this->scopeOf($a1)], [$a2, $this->scopeOf($a2)], [$b, $this->scopeOf($b)], [$ro, $this->scopeOf($ro)]];

        self::assertSame(3, $svc->warm($viewers));
        self::assertTrue($svc->snapshot($a2, $this->scopeOf($a2))['from_cache'], 'the next visitor is served from the warm cache');
        self::assertTrue($svc->snapshot($b, $this->scopeOf($b))['from_cache']);

        // warming refreshes even a still-fresh entry
        $before = $svc->snapshot($a1, $this->scopeOf($a1))['generated_at'];
        sleep(1);
        $svc->warm([[$a1, $this->scopeOf($a1)]]);
        self::assertGreaterThan($before, $svc->snapshot($a1, $this->scopeOf($a1))['generated_at']);

        $off = new DashboardService(
            $this->db, $this->app->get(\App\Repositories\DashboardRepository::class), $this->app->get(\App\Repositories\LeadRepository::class),
            $this->app->get(InvoiceRepository::class), $this->app->get(\App\Auth\PermissionService::class), $this->app->get(\App\Domain\StatusMachine::class), 0,
        );
        self::assertSame(0, $off->warm($viewers), 'nothing to warm when caching is off');
    }

    public function test_the_probe_repository_is_read_only_by_construction(): void
    {
        foreach ((new \ReflectionClass(IntegrityRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            self::assertDoesNotMatchRegularExpression('/^(update|delete|insert|fix|repair|set|save)/i', $m->getName(), 'a checker must never "fix" data silently');
        }
    }
}
