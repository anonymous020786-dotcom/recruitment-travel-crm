<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\ReportService;
use App\Services\TourBookingService;
use App\Services\TourPackageService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\JobValidator;
use App\Validators\TourBookingValidator;
use App\Validators\TourPackageValidator;
use Tests\Support\DbTestCase;

final class ReportServiceTest extends DbTestCase
{
    private ReportService $reports;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];
    /** @var list<int> */
    private array $sourceIds = [];
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
        $this->branchA = $this->branch('RPX-A');
        $this->branchB = $this->branch('RPX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'RPX-%')";
        $cand = "candidate_id IN (SELECT id FROM candidates WHERE {$like})";
        $this->db->affectingStatement("DELETE FROM flight_bookings WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM tour_bookings WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM tour_packages WHERE name LIKE 'RPX %'");
        $this->db->affectingStatement("DELETE FROM placements WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM visa_applications WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM medical_records WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM passports WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('tours', 'applications', 'jobs', 'employers', 'leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM persons WHERE full_name LIKE 'RPX %'");
        if ($this->personIds !== []) {
            $ph = implode(',', array_fill(0, count($this->personIds), '?'));
            $this->db->affectingStatement("DELETE FROM persons WHERE id IN ({$ph})", $this->personIds);
        }
        if ($this->sourceIds !== []) {
            $ph = implode(',', array_fill(0, count($this->sourceIds), '?'));
            $this->db->affectingStatement("DELETE FROM lead_sources WHERE id IN ({$ph})", $this->sourceIds);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'RPX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(tour_booking|job|employer|lead|candidate|application):'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'rp_' . bin2hex(random_bytes(4)) . '@dev.local',
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
        return '88' . str_pad((string) (random_int(1000, 9999) * 10000 + ++$this->seq), 8, '0', STR_PAD_LEFT);
    }

    private function source(string $name): int
    {
        $id = (int) $this->db->insertRow('lead_sources', ['name' => $name, 'is_active' => 1]);
        $this->sourceIds[] = $id;

        return $id;
    }

    private function lead(User $actor, int $branch, ?int $sourceId, bool $convert = false): ?Candidate
    {
        $leads = $this->app->get(LeadService::class);
        $data = ['name' => 'RPX Lead', 'phone' => $this->phone(), 'priority' => 'medium'] + ($sourceId !== null ? ['source_id' => $sourceId] : []);
        $lead = $leads->create($data, $actor, $branch, confirmedNotDuplicate: true);
        if (!$convert) {
            return null;
        }
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    private function application(User $actor, Candidate $c, string $employerName): Application
    {
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => $employerName, 'country' => 'AE', 'status' => 'active'], $actor, $c->branchId);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);

        return $this->app->get(ApplicationService::class)->create($c, $job, $actor);
    }

    private function in(int $days): string
    {
        return gmdate('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
    }

    /** @return array{c1:Candidate,c2:Candidate} */
    private function seedBranchA(User $manager): array
    {
        $web = $this->source('RPX Website');
        $walk = $this->source('=RPX Walk-in');   // a name that would run as a formula in a spreadsheet
        $this->lead($manager, $this->branchA, $web);
        $c1 = $this->lead($manager, $this->branchA, $web, true);
        $c2 = $this->lead($manager, $this->branchA, $walk, true);
        $this->lead($manager, $this->branchA, null);

        $a1 = $this->application($manager, $c1, 'RPX Alpha Co');
        $a2 = $this->application($manager, $c2, 'RPX Beta Co');
        $this->db->affectingStatement("UPDATE applications SET status = 'placed' WHERE id = ?", [$a1->id]);
        $this->db->affectingStatement("UPDATE applications SET status = 'rejected' WHERE id = ?", [$a2->id]);

        $this->db->insertRow('placements', [
            'public_id' => Ulid::generate(), 'candidate_id' => $c1->id, 'application_id' => $a1->id, 'employer_id' => $a1->employerId, 'job_id' => $a1->jobId,
            'branch_id' => $this->branchA, 'placed_on' => $this->in(-5), 'monthly_salary' => '2500.00', 'currency' => 'AED', 'status' => 'active',
        ]);
        $this->db->insertRow('flight_bookings', [
            'public_id' => Ulid::generate(), 'candidate_id' => $c1->id, 'application_id' => $a1->id, 'pnr' => 'RPX123', 'airline' => 'Air India',
            'flight_number' => 'AI995', 'departure_airport' => 'DEL', 'arrival_airport' => 'DXB', 'departure_at' => $this->in(-6) . ' 10:30:00', 'status' => 'flown', 'created_by' => $manager->id,
        ]);
        $this->db->insertRow('visa_applications', ['public_id' => Ulid::generate(), 'candidate_id' => $c1->id, 'country' => 'AE', 'status' => 'approved', 'expiry_date' => $this->in(10), 'created_by' => $manager->id]);
        $this->db->insertRow('medical_records', ['public_id' => Ulid::generate(), 'candidate_id' => $c2->id, 'result' => 'fit', 'status' => 'fit', 'expires_at' => $this->in(20), 'created_by' => $manager->id, 'medical_center' => 'Gulf Clinic']);
        $this->db->insertRow('passports', ['candidate_id' => $c1->id, 'passport_number' => 'RPX' . random_int(100000, 999999), 'expiry_date' => $this->in(5)]);

        $packages = $this->app->get(TourPackageService::class);
        $pkg = $packages->create((new TourPackageValidator())->validate(['name' => 'RPX Dubai', 'destination' => 'Dubai', 'price' => '1000', 'currency' => 'inr']), $manager);
        $pkg = $packages->changeStatus($pkg, 'active', $manager);
        $v = new TourBookingValidator();
        $tours = $this->app->get(TourBookingService::class);
        $b1 = $tours->create($v->customer(['customer_name' => 'RPX Trav', 'customer_phone' => $this->phone()]), $v->trip(['adults' => '2', 'package' => $pkg->publicId, 'travel_date' => $this->in(20)]), $manager);
        $tours->changeStatus($b1, 'confirmed', $manager, $b1->recordVersion);
        $tours->create($v->customer(['customer_name' => 'RPX Trav', 'customer_phone' => $this->phone()]), $v->trip(['adults' => '1', 'package' => $pkg->publicId]), $manager);

        return ['c1' => $c1, 'c2' => $c2];
    }

    /** @return list<list<string|int>> */
    private function rowsOf(string $key, User $user, array $input = []): array
    {
        return iterator_to_array($this->reports->rows($key, $this->reports->filters($key, $input), $this->scopeOf($user), $user), false);
    }

    // ---- catalogue and permissions ---------------------------------

    public function test_the_catalogue_follows_permissions(): void
    {
        $manager = $this->actor('manager');
        $keys = static fn (array $cat): array => array_merge(...array_map(static fn (array $g): array => array_column($g, 'key'), array_values($cat)));

        self::assertEqualsCanonicalizing(
            ['lead-sources', 'recruitment-funnel', 'applications-by-employer', 'placements', 'expiring-documents', 'flights', 'tour-packages', 'collections', 'payments-register', 'invoices-register', 'refunds-register', 'overdue-invoices'],
            $keys($this->reports->catalogFor($manager)),
        );

        $accounts = $this->actor('accounts');
        $acc = $keys($this->reports->catalogFor($accounts));
        self::assertNotContains('tour-packages', $acc, 'the accounts desk cannot see tour bookings');
        self::assertNotContains('flights', $acc);
        self::assertNull($this->reports->definition('flights', $accounts));
        self::assertNull($this->reports->definition('no-such-report', $manager));
        self::assertSame('Flights', $this->reports->definition('flights', $manager)['title']);
    }

    public function test_filters_are_validated_and_defaulted(): void
    {
        $d = $this->reports->filters('placements', []);
        self::assertSame(gmdate('Y-m-d'), $d['to']);
        self::assertSame(gmdate('Y-m-d', strtotime('-89 days')), $d['from'], 'defaults to the last 90 days');
        self::assertSame(60, $d['days']);

        self::assertSame(['from' => '2026-01-01', 'to' => '2026-01-31'], array_intersect_key($this->reports->filters('placements', ['from' => '2026-01-01', 'to' => '2026-01-31']), ['from' => 1, 'to' => 1]));
        self::assertSame(30, $this->reports->filters('expiring-documents', ['days' => '30', 'from' => 'garbage'])['days'], 'a non-range report ignores the dates');

        foreach ([
            ['placements', ['from' => '31/01/2026']],
            ['placements', ['from' => '2026-02-01', 'to' => '2026-01-01']],
            ['placements', ['from' => '2015-01-01', 'to' => '2026-01-01']],
            ['expiring-documents', ['days' => '0']],
            ['expiring-documents', ['days' => '999']],
            ['expiring-documents', ['days' => 'soon']],
        ] as [$key, $input]) {
            try {
                $this->reports->filters($key, $input);
                self::fail('accepted ' . json_encode($input));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    // ---- the recruitment funnel ------------------------------------

    private function moved(Application $a, User $by, string ...$statuses): void
    {
        foreach ($statuses as $to) {
            $this->db->insertRow('application_status_history', ['application_id' => $a->id, 'from_status' => null, 'to_status' => $to, 'changed_by' => $by->id]);
        }
    }

    public function test_the_funnel_counts_who_reached_each_stage_even_if_they_moved_on_or_dropped_out(): void
    {
        $manager = $this->actor('manager');
        $c = $this->lead($manager, $this->branchA, null, true);

        $placed = $this->application($manager, $c, 'RPX Fun 1');
        $this->moved($placed, $manager, 'shortlisted', 'interview_completed', 'selected', 'offer_accepted', 'medical_completed', 'visa_approved', 'departed');
        $this->db->affectingStatement("UPDATE applications SET status = 'placed' WHERE id = ?", [$placed->id]);

        $scheduled = $this->application($manager, $c, 'RPX Fun 2');
        $this->moved($scheduled, $manager, 'shortlisted', 'interview_scheduled');
        $this->db->affectingStatement("UPDATE applications SET status = 'interview_scheduled' WHERE id = ?", [$scheduled->id]);

        $rejected = $this->application($manager, $c, 'RPX Fun 3');
        $this->moved($rejected, $manager, 'shortlisted', 'interview_completed', 'rejected');
        $this->db->affectingStatement("UPDATE applications SET status = 'rejected' WHERE id = ?", [$rejected->id]);

        $this->application($manager, $c, 'RPX Fun 4');   // still just applied

        // outside the period, and in another branch: neither may be counted
        $old = $this->application($manager, $c, 'RPX Fun 5');
        $this->db->affectingStatement("UPDATE applications SET applied_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 200 DAY), status = 'placed' WHERE id = ?", [$old->id]);
        $other = $this->actor('manager', $this->branchB);
        $cB = $this->lead($other, $this->branchB, null, true);
        $this->db->affectingStatement("UPDATE applications SET status = 'placed' WHERE id = ?", [$this->application($other, $cB, 'RPX Fun 6')->id]);

        $rows = $this->rowsOf('recruitment-funnel', $manager);

        self::assertSame(
            [['Applied', 4, '100.0', ''], ['Shortlisted', 3, '75.0', '75.0'], ['Interviewed', 2, '50.0', '66.7'], ['Selected by employer', 1, '25.0', '50.0'],
                ['Offer accepted', 1, '25.0', '100.0'], ['Medical completed', 1, '25.0', '100.0'], ['Visa approved', 1, '25.0', '100.0'],
                ['Departed', 1, '25.0', '100.0'], ['Placed', 1, '25.0', '100.0']],
            $rows,
        );
        self::assertSame(1, $this->rowsOf('recruitment-funnel', $other)[0][1], 'the other branch sees only its own application');
    }

    public function test_an_empty_period_gives_a_zero_funnel_not_an_error(): void
    {
        $manager = $this->actor('manager');

        $rows = $this->rowsOf('recruitment-funnel', $manager, ['from' => '2001-01-01', 'to' => '2001-01-31']);

        self::assertCount(9, $rows);
        foreach ($rows as $r) {
            self::assertSame(0, $r[1]);
            self::assertSame('0.0', $r[2]);
        }
    }
    // ---- the reports -----------------------------------------------

    public function test_every_report_returns_the_right_rows_for_the_viewers_branch_only(): void
    {
        $manager = $this->actor('manager');
        $this->seedBranchA($manager);

        // branch B has rows the viewer must never see
        $other = $this->actor('manager', $this->branchB);
        $cB = $this->lead($other, $this->branchB, $this->source('RPX Elsewhere'), true);
        $aB = $this->application($other, $cB, 'RPX Gamma Co');
        $this->db->insertRow('placements', [
            'public_id' => Ulid::generate(), 'candidate_id' => $cB->id, 'application_id' => $aB->id, 'employer_id' => $aB->employerId, 'job_id' => $aB->jobId,
            'branch_id' => $this->branchB, 'placed_on' => $this->in(-1), 'status' => 'active',
        ]);

        $sources = [];
        foreach ($this->rowsOf('lead-sources', $manager) as $r) {
            $sources[$r[0]] = $r;
        }
        self::assertSame(2, $sources['RPX Website'][1], 'two leads came from the website');
        self::assertSame(1, $sources['RPX Website'][2], 'one of them converted');
        self::assertSame('50.0', $sources['RPX Website'][3]);
        self::assertSame(1, $sources['=RPX Walk-in'][1]);
        self::assertSame('100.0', $sources['=RPX Walk-in'][3]);
        self::assertSame(1, $sources['(no source)'][1]);
        self::assertArrayNotHasKey('RPX Elsewhere', $sources);

        $emp = [];
        foreach ($this->rowsOf('applications-by-employer', $manager) as $r) {
            $emp[$r[0]] = $r;
        }
        self::assertSame(['RPX Alpha Co', 1, 0, 1, 0, 0], $emp['RPX Alpha Co']);
        self::assertSame(['RPX Beta Co', 1, 0, 0, 1, 0], $emp['RPX Beta Co']);
        self::assertArrayNotHasKey('RPX Gamma Co', $emp);

        $placements = $this->rowsOf('placements', $manager);
        self::assertCount(1, $placements);
        self::assertSame([$this->in(-5), 'RPX Lead'], array_slice($placements[0], 0, 2));
        self::assertSame(['RPX Alpha Co', 'Driver', 'AE', '2500.00', 'AED'], array_slice($placements[0], 3, 5));

        $flights = $this->rowsOf('flights', $manager);
        self::assertCount(1, $flights);
        self::assertSame('DEL → DXB', $flights[0][4]);
        self::assertSame('RPX123', $flights[0][7]);
        self::assertSame('Flown', $flights[0][8]);
        self::assertSame([], $this->rowsOf('flights', $manager, ['from' => $this->in(-3), 'to' => $this->in(0)]), 'the date range filters');

        $exp = $this->rowsOf('expiring-documents', $manager, ['days' => '30']);
        self::assertSame(['Passport', 'Visa', 'Medical certificate'], array_column($exp, 0), 'soonest first');
        self::assertSame([5, 10, 20], array_column($exp, 5));
        self::assertCount(2, $this->rowsOf('expiring-documents', $manager, ['days' => '12']));

        $tours = $this->rowsOf('tour-packages', $manager);
        self::assertCount(1, $tours);
        self::assertSame(['RPX Dubai', 'INR', 2, 1, 1, 0, 0, '2000.00'], $tours[0], 'two travellers × 1,000 confirmed; the second booking is still in the pipeline');

        $t = $this->rowsOf('placements', $other);
        self::assertCount(1, $t, 'the other branch sees only its own placement');
    }

    public function test_expiring_documents_only_include_the_kinds_the_viewer_may_see(): void
    {
        $manager = $this->actor('manager');
        $this->seedBranchA($manager);
        $perms = $this->app->get(\App\Auth\PermissionService::class);

        foreach (array_keys($this->roles) as $role) {
            if ($role === 'super_admin') {
                continue;
            }
            $u = $this->actor($role);
            if (!$perms->userCan($u, 'candidates.view')) {
                self::assertNull($this->reports->definition('expiring-documents', $u));
                continue;
            }
            $types = array_unique(array_column($this->rowsOf('expiring-documents', $u, ['days' => '30']), 0));
            self::assertSame($perms->userCan($u, 'visa.view'), in_array('Visa', $types, true), "{$role}: visas follow visa.view");
            self::assertSame($perms->userCan($u, 'medical.view'), in_array('Medical certificate', $types, true), "{$role}: medicals follow medical.view");
            self::assertTrue(in_array('Passport', $types, true), "{$role}: passports follow candidates.view");
        }
    }

    // ---- screen and CSV --------------------------------------------

    public function test_the_screen_page_is_capped_and_says_so(): void
    {
        $manager = $this->actor('manager');
        $this->seedBranchA($manager);
        $filters = $this->reports->filters('lead-sources', []);

        $full = $this->reports->page('lead-sources', $filters, $this->scopeOf($manager), $manager);
        self::assertFalse($full['truncated']);
        self::assertCount(3, $full['rows']);

        $cut = $this->reports->page('lead-sources', $filters, $this->scopeOf($manager), $manager, 2);
        self::assertTrue($cut['truncated']);
        self::assertCount(2, $cut['rows']);

        $exact = $this->reports->page('lead-sources', $filters, $this->scopeOf($manager), $manager, 3);
        self::assertFalse($exact['truncated'], 'exactly the limit is not "more"');
    }

    public function test_csv_export_streams_a_header_and_every_row_and_neutralises_formulas(): void
    {
        $manager = $this->actor('manager');
        $this->seedBranchA($manager);
        $filters = $this->reports->filters('lead-sources', []);

        $h = fopen('php://temp', 'w+b');
        $n = $this->reports->exportCsv('lead-sources', $filters, $this->scopeOf($manager), $manager, $h);
        rewind($h);
        $lines = [];
        while (($row = fgetcsv($h)) !== false) {
            $lines[] = $row;
        }
        fclose($h);

        self::assertSame(3, $n);
        self::assertSame(['Source', 'Leads', 'Converted', 'Conversion %'], $lines[0]);
        self::assertCount(4, $lines);
        $names = array_column($lines, 0);
        self::assertContains("'=RPX Walk-in", $names, 'a source starting with = is written as text, not a formula');
        self::assertNotContains('=RPX Walk-in', $names);

        // the cap stops the file, not the header
        $h = fopen('php://temp', 'w+b');
        self::assertSame(1, $this->reports->exportCsv('lead-sources', $filters, $this->scopeOf($manager), $manager, $h, 1));
        rewind($h);
        self::assertCount(2, array_filter(explode("\n", trim((string) stream_get_contents($h)))));
        fclose($h);

        $this->expectException(\InvalidArgumentException::class);
        $this->reports->exportCsv('nope', $filters, $this->scopeOf($manager), $manager, fopen('php://temp', 'w+b'));
    }
}
