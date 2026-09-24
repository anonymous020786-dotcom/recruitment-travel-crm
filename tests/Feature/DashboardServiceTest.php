<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\DashboardRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\LeadRepository;
use App\Repositories\UserRepository;
use App\Services\ApplicationService;
use App\Services\DashboardService;
use App\Services\EmployerService;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\PaymentService;
use App\Services\TourBookingService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\InvoiceValidator;
use App\Validators\JobValidator;
use App\Validators\PaymentValidator;
use App\Validators\TourBookingValidator;
use Tests\Support\DbTestCase;

final class DashboardServiceTest extends DbTestCase
{
    private DashboardService $live;
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
        $this->live = $this->service(0);
        $this->branchA = $this->branch('DBX-A');
        $this->branchB = $this->branch('DBX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'DBX-%')";
        $cand = "candidate_id IN (SELECT id FROM candidates WHERE {$like})";
        $this->db->affectingStatement("DELETE FROM settings WHERE key_name LIKE 'dash:%'");
        $this->db->affectingStatement("DELETE FROM refunds WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM receipts WHERE payment_id IN (SELECT id FROM payments WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM payment_allocations WHERE payment_id IN (SELECT id FROM payments WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM payments WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM invoices WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM tour_bookings WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM placements WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM interviews WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM visa_applications WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM medical_records WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM passports WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('payments', 'invoices', 'tours', 'applications', 'jobs', 'employers', 'leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM persons WHERE full_name LIKE 'DBX %'");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'DBX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(invoice|payment|receipt|tour_booking|job|employer|lead|candidate|application):'");
    }

    // ---- fixtures --------------------------------------------------

    private function service(int $cacheSeconds): DashboardService
    {
        return new DashboardService(
            $this->db, $this->app->get(DashboardRepository::class), $this->app->get(LeadRepository::class), $this->app->get(InvoiceRepository::class),
            $this->app->get(\App\Auth\PermissionService::class), $this->app->get(\App\Domain\StatusMachine::class), $cacheSeconds,
        );
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2))]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'db_' . bin2hex(random_bytes(4)) . '@dev.local',
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

    private function phone(): string
    {
        return '89' . str_pad((string) (random_int(1000, 9999) * 10000 + ++$this->seq), 8, '0', STR_PAD_LEFT);
    }

    private function lead(User $actor, int $branch, bool $convert = false): ?Candidate
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'DBX Lead', 'phone' => $this->phone(), 'priority' => 'medium'], $actor, $branch, confirmedNotDuplicate: true);
        if (!$convert) {
            return null;
        }
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    private function application(User $actor, Candidate $c): Application
    {
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'DBX Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $c->branchId);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);

        return $this->app->get(ApplicationService::class)->create($c, $job, $actor);
    }

    /** Branch A: a realistic slice of every module. Returns the pieces tests may want. */
    private function seedBranchA(User $manager): void
    {
        $this->lead($manager, $this->branchA); // stays new
        $c1 = $this->lead($manager, $this->branchA, true);
        $c2 = $this->lead($manager, $this->branchA, true);
        $a1 = $this->application($manager, $c1);
        $a2 = $this->application($manager, $c2);
        $this->db->affectingStatement("UPDATE applications SET status = 'ticket_pending' WHERE id = ?", [$a1->id]);
        $this->db->affectingStatement("UPDATE applications SET status = 'visa_approved' WHERE id = ?", [$a2->id]);

        $in = static fn (int $days): string => gmdate('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
        foreach ([0, 1] as $offset) {
            $this->db->insertRow('interviews', [
                'public_id' => Ulid::generate(), 'application_id' => $a1->id, 'candidate_id' => $c1->id, 'job_id' => $a1->jobId, 'employer_id' => $a1->employerId,
                'round_no' => $offset + 1, 'type' => 'video', 'scheduled_date' => $in($offset), 'status' => 'scheduled', 'created_by' => $manager->id,
            ]);
        }
        $this->db->insertRow('visa_applications', ['public_id' => Ulid::generate(), 'candidate_id' => $c1->id, 'country' => 'AE', 'status' => 'approved', 'expiry_date' => $in(10), 'created_by' => $manager->id]);
        $this->db->insertRow('medical_records', ['public_id' => Ulid::generate(), 'candidate_id' => $c1->id, 'result' => 'fit', 'status' => 'fit', 'expires_at' => $in(20), 'created_by' => $manager->id]);
        $this->db->insertRow('passports', ['candidate_id' => $c1->id, 'passport_number' => 'DBX' . random_int(100000, 999999), 'expiry_date' => $in(5)]);
        $this->db->insertRow('placements', [
            'public_id' => Ulid::generate(), 'candidate_id' => $c2->id, 'application_id' => $a2->id, 'employer_id' => $a2->employerId, 'job_id' => $a2->jobId,
            'branch_id' => $this->branchA, 'placed_on' => gmdate('Y-m-d'), 'status' => 'active',
        ]);

        $v = new TourBookingValidator();
        $this->app->get(TourBookingService::class)->create(
            $v->customer(['customer_name' => 'DBX Traveller', 'customer_phone' => $this->phone()]),
            $v->trip(['adults' => '2', 'travel_date' => $in(10), 'total_amount' => '5000', 'currency' => 'inr']),
            $manager,
        );

        $inv = $this->app->get(InvoiceService::class)->createForApplication($a1, (new InvoiceValidator())->invoice([
            'currency' => 'inr', 'line_description' => ['Fee'], 'line_quantity' => ['1'], 'line_unit_price' => ['1000'],
        ]), $manager);
        $inv = $this->app->get(InvoiceService::class)->issue($inv, $manager, $inv->recordVersion);
        $this->db->affectingStatement('UPDATE invoices SET due_on = ? WHERE id = ?', [$in(-3), $inv->id]);
        $pay = $this->app->get(PaymentService::class)->recordForInvoice($this->app->get(InvoiceRepository::class)->findById($inv->id, $this->scopeOf($manager)), (new PaymentValidator())->payment(['amount' => '400', 'method' => 'cash']), $manager)['payment'];
        $this->db->insertRow('refunds', [
            'public_id' => Ulid::generate(), 'refund_number' => 'RF-DBX-' . bin2hex(random_bytes(3)), 'payment_id' => $pay->id, 'invoice_id' => $inv->id,
            'person_id' => $pay->personId, 'branch_id' => $this->branchA, 'amount' => '50.00', 'method' => 'cash', 'reason' => 'test', 'status' => 'pending', 'created_by' => $manager->id,
        ]);
    }

    // ---- tests -----------------------------------------------------

    public function test_the_snapshot_reports_every_module_for_the_viewers_branch_only(): void
    {
        $manager = $this->actor('manager');
        $this->seedBranchA($manager);

        // branch B has data the viewer must not see
        $otherManager = $this->actor('manager', $this->branchB);
        $this->lead($otherManager, $this->branchB);
        $this->lead($otherManager, $this->branchB, true);

        $s = $this->live->snapshot($manager, $this->scopeOf($manager));

        self::assertSame(1, $s['leads']['open'], 'one new lead; the two converted ones are not open');
        self::assertSame(1, $s['leads']['by_status']['new']);
        self::assertSame(2, $s['leads']['by_status']['converted']);
        self::assertSame(2, $s['candidates']['total']);
        self::assertSame(2, $s['candidates']['new_month']);
        self::assertSame(2, $s['pipeline']['live']);
        self::assertSame(['visa_approved' => 1, 'ticket_pending' => 1], $s['pipeline']['by_status'], 'in pipeline order');
        self::assertSame(['visa_approved' => 1, 'ticket_pending' => 1, 'ticket_booked' => 0, 'departed' => 0], $s['travel']['stages']);
        self::assertSame(1, $s['travel']['placed_month']);
        self::assertSame(1, $s['interviews']['today']);
        self::assertSame(2, $s['interviews']['next7']);
        self::assertSame(['approved' => 1, 'expiring' => 1], $s['visa']);
        self::assertSame(1, $s['medical']['expiring']);
        self::assertSame(1, $s['passports']['expiring']);
        self::assertSame(['inquiry' => 1], $s['tours']['by_status']);
        self::assertSame(1, $s['tours']['upcoming']);
        self::assertSame(1, $s['refunds']['pending']);

        $inr = null;
        foreach ($s['finance']['summary'] as $row) {
            if ($row['currency'] === 'INR') {
                $inr = $row;
            }
        }
        self::assertNotNull($inr);
        self::assertSame('1000.00', $inr['billed']);
        self::assertSame('600.00', $inr['outstanding']);
        self::assertSame('600.00', $inr['overdue']);
        self::assertSame('400.00', $s['finance']['collected']['INR']);

        self::assertCount(6, $s['months']);
        self::assertSame(gmdate('Y-m'), end($s['months']));
        self::assertSame(2, end($s['candidates']['monthly']), 'this month is the last bar');
        self::assertSame(1, end($s['travel']['monthly']));
        self::assertSame([0, 0, 0, 0, 0, 2], $s['candidates']['monthly']);

        // the other branch sees only its own
        $t = $this->live->snapshot($otherManager, $this->scopeOf($otherManager));
        self::assertSame(1, $t['leads']['open']);
        self::assertSame(1, $t['candidates']['total']);
        self::assertSame(0, $t['pipeline']['live']);
        self::assertSame([], $t['finance']['summary']);
        self::assertSame([], $t['finance']['collected']);
        self::assertSame(0, $t['refunds']['pending']);
    }

    public function test_the_attention_list_names_only_what_needs_doing(): void
    {
        $manager = $this->actor('manager');
        $this->seedBranchA($manager);
        $labels = [];
        foreach ($this->live->snapshot($manager, $this->scopeOf($manager))['attention'] as $a) {
            $labels[$a['label']] = $a['count'];
            self::assertStringStartsWith('/', $a['href']);
        }

        self::assertSame(1, $labels['Interviews today']);
        self::assertSame(1, $labels['Visas expiring within 30 days']);
        self::assertSame(1, $labels['Medical certificates expiring within 30 days']);
        self::assertSame(1, $labels['Passports expiring within 30 days']);
        self::assertSame(2, $labels['Candidates awaiting a ticket']);
        self::assertSame(1, $labels['Tours starting in the next 30 days']);
        self::assertSame(1, $labels['Refunds waiting for approval']);
        self::assertArrayHasKey('Overdue invoices (INR 600.00)', $labels);

        $empty = $this->actor('manager', $this->branchB);
        self::assertSame([], $this->live->snapshot($empty, $this->scopeOf($empty))['attention'], 'nothing to chase in an empty branch');
    }

    public function test_widgets_follow_permissions(): void
    {
        $manager = $this->actor('manager');
        $this->seedBranchA($manager);

        $all = $this->live->groupsFor($manager);
        foreach (['leads', 'candidates', 'pipeline', 'interviews', 'visa', 'medical', 'travel', 'tours', 'finance', 'refunds', 'passports'] as $g) {
            self::assertContains($g, $all);
        }

        $readOnly = $this->actor('read_only');
        $ro = $this->live->snapshot($readOnly, $this->scopeOf($readOnly));
        self::assertNotContains('refunds', $ro['groups'], 'read_only cannot approve refunds');
        self::assertArrayNotHasKey('refunds', $ro);
        self::assertArrayHasKey('finance', $ro, 'read_only may view invoices');

        $counselor = $this->actor('counselor');
        $co = $this->live->snapshot($counselor, $this->scopeOf($counselor));
        foreach (['finance', 'tours', 'refunds'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $co, "a counselor has no {$hidden} widget");
        }
        self::assertArrayHasKey('leads', $co);

        $accounts = $this->actor('accounts');
        $ac = $this->live->snapshot($accounts, $this->scopeOf($accounts));
        self::assertArrayHasKey('finance', $ac);
        self::assertArrayNotHasKey('tours', $ac, 'the accounts desk has no tours widget');
        self::assertArrayNotHasKey('travel', $ac);
        self::assertSame('600.00', $ac['finance']['summary'][0]['outstanding']);
    }

    public function test_snapshots_are_cached_per_scope_and_widget_set_and_expire(): void
    {
        $manager = $this->actor('manager');
        $svc = $this->service(60);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $scope = $this->scopeOf($manager);

        $first = $svc->snapshot($manager, $scope, $now);
        self::assertArrayNotHasKey('from_cache', $first);
        self::assertSame(0, $first['leads']['open']);

        $this->lead($manager, $this->branchA);
        $again = $svc->snapshot($manager, $scope, $now->modify('+30 seconds'));
        self::assertTrue($again['from_cache']);
        self::assertSame(0, $again['leads']['open'], 'a new lead does not show until the snapshot expires');

        $fresh = $svc->snapshot($manager, $scope, $now->modify('+61 seconds'));
        self::assertArrayNotHasKey('from_cache', $fresh);
        self::assertSame(1, $fresh['leads']['open']);

        // another branch scope, or another widget set, is a different cache entry
        $other = $this->actor('manager', $this->branchB);
        self::assertArrayNotHasKey('from_cache', $svc->snapshot($other, $this->scopeOf($other), $now->modify('+62 seconds')));
        $counselor = $this->actor('counselor');
        self::assertArrayNotHasKey('from_cache', $svc->snapshot($counselor, $this->scopeOf($counselor), $now->modify('+62 seconds')));

        // two people with the same scope and widgets share one entry
        $twin = $this->actor('manager');
        self::assertTrue($svc->snapshot($twin, $this->scopeOf($twin), $now->modify('+70 seconds'))['from_cache']);

        // caching off means always live
        $off = $this->service(0);
        self::assertSame(1, $off->snapshot($manager, $scope)['leads']['open']);
        self::assertArrayNotHasKey('from_cache', $off->snapshot($manager, $scope));
    }

    public function test_the_repository_refuses_non_identifiers_in_its_dynamic_parts(): void
    {
        $repo = $this->app->get(DashboardRepository::class);
        $scope = $this->scopeOf($this->actor('manager'));

        $this->expectException(\InvalidArgumentException::class);
        $repo->monthly('leads; DROP TABLE leads', 'created_at', 'branch_id', $scope);
    }
}
