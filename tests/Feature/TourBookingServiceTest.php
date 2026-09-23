<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\TourBooking;
use App\Models\TourPackage;
use App\Models\User;
use App\Repositories\TourBookingHistoryRepository;
use App\Repositories\TourBookingRepository;
use App\Repositories\UserRepository;
use App\Services\LeadService;
use App\Services\TourBookingService;
use App\Services\TourPackageService;
use App\Support\Hash;
use App\Support\ListQuery;
use App\Support\Ulid;
use App\Validators\TourBookingValidator;
use App\Validators\TourPackageValidator;
use Tests\Support\DbTestCase;

final class TourBookingServiceTest extends DbTestCase
{
    private const PREFIX = 'TBX ';

    private TourBookingService $service;
    private TourBookingRepository $repo;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];
    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(TourBookingService::class);
        $this->repo = $this->app->get(TourBookingRepository::class);
        $this->branchA = $this->branch('TBX-A');
        $this->branchB = $this->branch('TBX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'TBX-%')";
        $this->db->affectingStatement("DELETE FROM tour_bookings WHERE {$like}"); // history cascades
        $this->db->affectingStatement("DELETE FROM tour_packages WHERE name LIKE 'TBX %'");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('tours', 'leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM persons WHERE full_name LIKE 'TBX %'");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'TBX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(tour_booking|lead|candidate):'");
    }

    // ---- fixtures --------------------------------------------------

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'tb_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function scope(?User $for = null): \App\Auth\BranchScope
    {
        $user = $for ?? $this->app->get(UserRepository::class)->findById($this->userIds[0]);

        return $this->app->get(BranchScopeResolver::class)->resolve($user);
    }

    private function phone(): string
    {
        return '95' . str_pad((string) (random_int(1000, 9999) * 10000 + ++$this->phoneSeq), 8, '0', STR_PAD_LEFT);
    }

    /** @return array{full_name:string,primary_phone:string,email:?string} */
    private function customer(string $name = 'Asha Rao', ?string $phone = null, string $email = ''): array
    {
        return (new TourBookingValidator())->customer(['customer_name' => self::PREFIX . $name, 'customer_phone' => $phone ?? $this->phone(), 'customer_email' => $email]);
    }

    /** @param array<string,mixed> $extra */
    private function trip(array $extra = []): array
    {
        return (new TourBookingValidator())->trip($extra + ['adults' => '2', 'children' => '0']);
    }

    private function package(User $actor, string $name = 'Dubai Discovery', string $price = '45000'): TourPackage
    {
        $packages = $this->app->get(TourPackageService::class);
        $p = $packages->create((new TourPackageValidator())->validate([
            'name' => self::PREFIX . $name, 'destination' => 'Dubai', 'price' => $price, 'currency' => 'inr',
        ]), $actor);

        return $packages->changeStatus($p, 'active', $actor);
    }

    private function book(User $actor, array $trip = [], ?array $customer = null): TourBooking
    {
        return $this->service->create($customer ?? $this->customer(), $this->trip($trip), $actor);
    }

    private function fresh(TourBooking $b): TourBooking
    {
        return $this->repo->findById($b->id, $this->scope());
    }

    /** @return list<string> the statuses the booking moved through, oldest first */
    private function trail(TourBooking $b): array
    {
        return array_reverse(array_map(static fn (array $h): string => $h['to'], $this->app->get(TourBookingHistoryRepository::class)->forBooking($b->id)));
    }

    // ---- create ----------------------------------------------------

    public function test_create_starts_an_inquiry_with_a_number_history_and_audit(): void
    {
        $actor = $this->actor();
        $b = $this->book($actor, ['travel_date' => gmdate('Y-m-d', strtotime('+30 days')), 'notes' => 'Window seat']);

        self::assertSame('inquiry', $b->status);
        self::assertMatchesRegularExpression('/^TB-\d{4}-\d{6}$/', $b->bookingNumber);
        self::assertSame($actor->id, $b->assignedTo, 'defaults to the person who took the booking');
        self::assertSame($this->branchA, $b->branchId);
        self::assertSame(1, $b->recordVersion);
        self::assertSame(2, $b->travellers());
        self::assertSame('2 adults', $b->travellersLabel());
        self::assertSame('Custom trip', $b->tripLabel());
        self::assertSame(['inquiry'], $this->trail($b));
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='tours' AND action='created' AND record_id = ?", [$b->id]));

        $second = $this->book($actor);
        self::assertNotSame($b->bookingNumber, $second->bookingNumber);
    }

    public function test_pricing_is_taken_from_the_package_unless_an_amount_is_given(): void
    {
        $actor = $this->actor();
        $pkg = $this->package($actor);

        $auto = $this->book($actor, ['package' => $pkg->publicId, 'adults' => '2', 'children' => '1']);
        self::assertSame('135000.00', $auto->totalAmount, '45,000 × 3 travellers');
        self::assertSame('INR', $auto->currency);
        self::assertSame($pkg->name, $auto->packageName);
        self::assertSame('2 adults, 1 child', $auto->travellersLabel());

        $manual = $this->book($actor, ['package' => $pkg->publicId, 'total_amount' => '99000', 'currency' => 'aed'], $this->customer('Ravi'));
        self::assertSame('99000.00', $manual->totalAmount);
        self::assertSame('AED', $manual->currency);

        $custom = $this->book($actor, [], $this->customer('Meena'));
        self::assertSame('0.00', $custom->totalAmount);
        self::assertSame('INR', $custom->currency);
    }

    public function test_the_customer_is_a_shared_person_matched_by_phone_or_email(): void
    {
        $actor = $this->actor();
        $phone = $this->phone();

        $first = $this->service->create($this->customer('Asha Rao', $phone, 'asha@example.test'), $this->trip(), $actor);
        $again = $this->service->create($this->customer('Asha R', $phone), $this->trip(['travel_date' => '2031-01-01']), $actor);
        self::assertSame($first->personId, $again->personId, 'same phone → same person');
        self::assertSame(self::PREFIX . 'Asha Rao', $again->customerName, 'the identity on file wins over the typed name');

        $byEmail = $this->service->create($this->customer('Someone Else', $this->phone(), 'asha@example.test'), $this->trip(['travel_date' => '2031-02-02']), $actor);
        self::assertSame($first->personId, $byEmail->personId, 'same email → same person');

        // a candidate created from a lead is the same human when they book a tour
        $leads = $this->app->get(LeadService::class);
        $candPhone = $this->phone();
        $lead = $leads->create(['name' => self::PREFIX . 'Candidate Kiran', 'phone' => $candPhone, 'priority' => 'medium'], $actor, $this->branchA, confirmedNotDuplicate: true);
        $candidate = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $candidate->personId;

        $tour = $this->service->create($this->customer('Kiran', $candPhone), $this->trip(), $actor);
        self::assertSame($candidate->personId, $tour->personId);
        self::assertCount(1, $this->repo->forPerson($candidate->personId, $this->scope()), 'visible from the candidate');
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM persons WHERE primary_phone = ?', [$candPhone]), 'no duplicate person');
    }

    public function test_the_same_customer_cannot_hold_two_open_bookings_for_one_trip_and_date(): void
    {
        $actor = $this->actor();
        $pkg = $this->package($actor);
        $phone = $this->phone();
        $trip = ['package' => $pkg->publicId, 'travel_date' => gmdate('Y-m-d', strtotime('+20 days'))];

        $first = $this->service->create($this->customer('Dup', $phone), $this->trip($trip), $actor);
        try {
            $this->service->create($this->customer('Dup', $phone), $this->trip($trip), $actor);
            self::fail('double submit must be refused');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        // a different date is a different trip
        $this->service->create($this->customer('Dup', $phone), $this->trip(['travel_date' => gmdate('Y-m-d', strtotime('+21 days'))] + $trip), $actor);

        // once the first is cancelled the slot is free again
        $this->service->changeStatus($first, 'cancelled', $actor, $first->recordVersion, 'Customer withdrew');
        self::assertSame('inquiry', $this->service->create($this->customer('Dup', $phone), $this->trip($trip), $actor)->status);
    }

    public function test_validator_rejects_bad_input(): void
    {
        $v = new TourBookingValidator();
        $badCustomer = [
            'no name'    => ['customer_name' => '', 'customer_phone' => '9876543210'],
            'no phone'   => ['customer_name' => 'A B', 'customer_phone' => ''],
            'bad phone'  => ['customer_name' => 'A B', 'customer_phone' => 'call me'],
            'bad email'  => ['customer_name' => 'A B', 'customer_phone' => '9876543210', 'customer_email' => 'nope'],
        ];
        foreach ($badCustomer as $why => $input) {
            try {
                $v->customer($input);
                self::fail("accepted: {$why}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }

        $badTrip = [
            'no adults'              => ['adults' => '0'],
            'return before travel'   => ['adults' => '1', 'travel_date' => '2030-05-10', 'return_date' => '2030-05-01'],
            'return without travel'  => ['adults' => '1', 'return_date' => '2030-05-01'],
            'garbage date'           => ['adults' => '1', 'travel_date' => '10/05/2030'],
            'bad package id'         => ['adults' => '1', 'package' => 'not-a-ulid'],
            'negative amount'        => ['adults' => '1', 'total_amount' => '-1'],
            'bad currency'           => ['adults' => '1', 'total_amount' => '10', 'currency' => 'RUPEES'],
            'too many children'      => ['adults' => '1', 'children' => '900'],
        ];
        foreach ($badTrip as $why => $input) {
            try {
                $v->trip($input);
                self::fail("accepted: {$why}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }

        $ok = $v->customer(['customer_name' => '  Asha   Rao ', 'customer_phone' => '+91  98765 43210', 'customer_email' => 'ASHA@Example.TEST']);
        self::assertSame('Asha Rao', $ok['full_name']);
        self::assertSame('+91 98765 43210', $ok['primary_phone']);
        self::assertSame('asha@example.test', $ok['email']);
    }

    public function test_package_rules(): void
    {
        $actor = $this->actor();
        $packages = $this->app->get(TourPackageService::class);
        $draft = $packages->create((new TourPackageValidator())->validate(['name' => self::PREFIX . 'Draft trip', 'destination' => 'X']), $actor);

        try {
            $this->book($actor, ['package' => $draft->publicId]);
            self::fail('only active packages can be booked');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        try {
            $this->book($actor, ['package' => Ulid::generate()]);
            self::fail('unknown package');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        // a booking keeps its package after the package is archived, and can still be edited
        $active = $this->package($actor, 'Later archived');
        $b = $this->book($actor, ['package' => $active->publicId]);
        $packages->changeStatus($this->app->get(\App\Repositories\TourPackageRepository::class)->findById($active->id), 'archived', $actor);
        $edited = $this->service->update($b, $this->trip(['package' => $active->publicId, 'adults' => '4']), $actor, $b->recordVersion);
        self::assertSame(4, $edited->adults);
        self::assertSame($active->id, $edited->packageId);
    }

    public function test_branch_and_assignee_rules_and_notification(): void
    {
        $manager = $this->actor();
        $agent = $this->actor('travel');
        $stranger = $this->actor('travel', $this->branchB);

        try {
            $this->service->create($this->customer(), $this->trip(['assigned_to' => (string) $stranger->id]), $manager);
            self::fail('an agent of another branch cannot be assigned');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        try {
            $this->service->create($this->customer('Wrong branch'), $this->trip(), $manager, $this->branchB);
            self::fail('cannot book into a branch outside the scope');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $b = $this->service->create($this->customer('Assigned'), $this->trip(['assigned_to' => (string) $agent->id]), $manager);
        self::assertSame($agent->id, $b->assignedTo);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'tour_booking_assigned'", [$agent->id]));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'tour_booking_assigned'", [$manager->id]), 'no self-notification');

        $own = $this->service->create($this->customer('Mine'), $this->trip(), $agent);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$agent->id]), 'booking for yourself does not notify');
        self::assertSame($agent->id, $own->assignedTo);
    }

    public function test_an_org_wide_user_without_a_primary_branch_must_choose_the_branch(): void
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => 'Org admin', 'email' => 'tb_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles['manager'], 'primary_branch_id' => null, 'is_org_wide' => 1, 'is_active' => 1,
        ]);
        $this->userIds[] = $id;
        $admin = $this->app->get(UserRepository::class)->findById($id);

        try {
            $this->service->create($this->customer('No branch'), $this->trip(), $admin);
            self::fail('there is no default branch to fall back on');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('branch', $e->errors());
        }

        $b = $this->service->create($this->customer('Chosen branch'), $this->trip(), $admin, $this->branchB);
        self::assertSame($this->branchB, $b->branchId);
        self::assertSame($admin->id, $b->assignedTo, 'an org-wide user can be assigned in any branch');
        self::assertSame($b->id, $this->repo->findById($b->id, $this->scope($admin))?->id);
    }

    // ---- lifecycle -------------------------------------------------

    public function test_the_full_trip_walks_the_pipeline_and_records_history(): void
    {
        $actor = $this->actor();
        $pkg = $this->package($actor);
        $b = $this->book($actor, ['package' => $pkg->publicId, 'travel_date' => gmdate('Y-m-d')]);

        foreach (['quoted', 'confirmed', 'travelling', 'completed'] as $to) {
            $b = $this->service->changeStatus($b, $to, $actor, $b->recordVersion);
            self::assertSame($to, $b->status);
        }

        self::assertSame(['inquiry', 'quoted', 'confirmed', 'travelling', 'completed'], $this->trail($b));
        self::assertSame(5, $b->recordVersion, 'one version bump per move');
        self::assertFalse($b->isOpen());

        $this->expectException(DomainRuleException::class);
        $this->service->changeStatus($b, 'cancelled', $actor, $b->recordVersion, 'too late');
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $actor = $this->actor();
        $b = $this->book($actor, ['total_amount' => '1000', 'currency' => 'inr', 'travel_date' => gmdate('Y-m-d', strtotime('+10 days'))]);

        foreach (['completed', 'travelling', 'inquiry'] as $to) {
            try {
                $this->service->changeStatus($b, $to, $actor, $b->recordVersion);
                self::fail("inquiry → {$to} must be refused");
            } catch (DomainRuleException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('inquiry', $this->fresh($b)->status);
    }

    public function test_business_gates_on_quote_confirm_and_start(): void
    {
        $actor = $this->actor();

        $noPrice = $this->book($actor);
        try {
            $this->service->changeStatus($noPrice, 'quoted', $actor, $noPrice->recordVersion);
            self::fail('a quote needs a price');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
        try {
            $this->service->changeStatus($noPrice, 'confirmed', $actor, $noPrice->recordVersion);
            self::fail('confirming needs a price');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $noDate = $this->book($actor, ['total_amount' => '500', 'currency' => 'inr'], $this->customer('No date'));
        try {
            $this->service->changeStatus($noDate, 'confirmed', $actor, $noDate->recordVersion);
            self::fail('confirming needs a travel date');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $past = $this->book($actor, ['total_amount' => '500', 'currency' => 'inr', 'travel_date' => '2020-01-01'], $this->customer('Past'));
        try {
            $this->service->changeStatus($past, 'confirmed', $actor, $past->recordVersion);
            self::fail('cannot confirm a trip dated in the past');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $future = $this->book($actor, ['total_amount' => '500', 'currency' => 'inr', 'travel_date' => gmdate('Y-m-d', strtotime('+15 days'))], $this->customer('Future'));
        $future = $this->service->changeStatus($future, 'confirmed', $actor, $future->recordVersion);
        try {
            $this->service->changeStatus($future, 'travelling', $actor, $future->recordVersion);
            self::fail('a trip cannot start before its travel date');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
    }

    public function test_cancelling_needs_a_reason_and_the_reason_is_kept(): void
    {
        $actor = $this->actor();
        $b = $this->book($actor);

        try {
            $this->service->changeStatus($b, 'cancelled', $actor, $b->recordVersion, '   ');
            self::fail('a reason is required');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $b = $this->service->changeStatus($b, 'cancelled', $actor, $b->recordVersion, 'Visa refused');
        self::assertSame('cancelled', $b->status);
        $history = $this->app->get(TourBookingHistoryRepository::class)->forBooking($b->id);
        self::assertSame('Visa refused', $history[0]['reason']);
        self::assertSame($actor->name, $history[0]['by']);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='tours' AND action='status_changed' AND record_id = ?", [$b->id]));
    }

    public function test_stale_versions_are_refused_and_change_nothing(): void
    {
        $actor = $this->actor();
        $b = $this->book($actor, ['total_amount' => '100', 'currency' => 'inr']);
        $moved = $this->service->changeStatus($b, 'quoted', $actor, $b->recordVersion); // now version 2

        foreach ([
            fn () => $this->service->changeStatus($b, 'cancelled', $actor, $b->recordVersion, 'stale'),
            fn () => $this->service->update($b, $this->trip(['adults' => '9', 'total_amount' => '100', 'currency' => 'inr']), $actor, $b->recordVersion),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('a stale write must be refused');
            } catch (StaleRecordException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('quoted', $this->fresh($b)->status);
        self::assertSame(2, $this->fresh($b)->adults, 'the stale edit changed nothing');
        self::assertSame(['inquiry', 'quoted'], $this->trail($moved));
    }

    // ---- update ----------------------------------------------------

    public function test_update_edits_an_open_booking_and_reprices_from_the_package_when_the_amount_is_blank(): void
    {
        $actor = $this->actor();
        $pkg = $this->package($actor);
        $b = $this->book($actor, ['package' => $pkg->publicId]);
        self::assertSame('90000.00', $b->totalAmount);

        $b = $this->service->update($b, $this->trip(['package' => $pkg->publicId, 'adults' => '4', 'children' => '2', 'travel_date' => '2031-06-01', 'return_date' => '2031-06-06']), $actor, $b->recordVersion);
        self::assertSame('270000.00', $b->totalAmount, '45,000 × 6');
        self::assertSame(6, $b->travellers());
        self::assertSame('2031-06-06', $b->returnDate);
        self::assertSame(2, $b->recordVersion);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='tours' AND action='updated' AND record_id = ?", [$b->id]));
    }

    public function test_a_confirmed_booking_keeps_its_price_and_date_and_closed_bookings_are_read_only(): void
    {
        $actor = $this->actor();
        $date = gmdate('Y-m-d', strtotime('+25 days'));
        $b = $this->book($actor, ['total_amount' => '5000', 'currency' => 'inr', 'travel_date' => $date]);
        $b = $this->service->changeStatus($b, 'confirmed', $actor, $b->recordVersion);

        foreach ([
            'blank price'  => ['total_amount' => '0', 'currency' => 'inr', 'travel_date' => $date],
            'no date'      => ['total_amount' => '5000', 'currency' => 'inr'],
            'date in past' => ['total_amount' => '5000', 'currency' => 'inr', 'travel_date' => '2020-01-01'],
        ] as $why => $trip) {
            try {
                $b = $this->fresh($b);
                $this->service->update($b, $this->trip($trip), $actor, $b->recordVersion);
                self::fail("a confirmed booking must refuse: {$why}");
            } catch (DomainRuleException) {
                self::assertTrue(true);
            }
        }

        $b = $this->fresh($b);
        $b = $this->service->update($b, $this->trip(['total_amount' => '5200', 'currency' => 'inr', 'travel_date' => $date, 'adults' => '3']), $actor, $b->recordVersion);
        self::assertSame('5200.00', $b->totalAmount);

        $cancelled = $this->service->changeStatus($b, 'cancelled', $actor, $b->recordVersion, 'Changed plans');
        $this->expectException(DomainRuleException::class);
        $this->service->update($cancelled, $this->trip(), $actor, $cancelled->recordVersion);
    }

    // ---- permissions -----------------------------------------------

    public function test_permissions_and_branch_scope(): void
    {
        $manager = $this->actor();
        $b = $this->book($manager, ['total_amount' => '100', 'currency' => 'inr']);

        foreach (['read_only', 'counselor'] as $role) {
            $denied = $this->actor($role);
            foreach ([
                fn () => $this->service->create($this->customer('Denied ' . $role), $this->trip(), $denied),
                fn () => $this->service->update($b, $this->trip(), $denied, $b->recordVersion),
                fn () => $this->service->changeStatus($b, 'quoted', $denied, $b->recordVersion),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail("{$role} must not manage tour bookings");
                } catch (AuthorizationException) {
                    self::assertTrue(true);
                }
            }
        }

        $elsewhere = $this->actor('manager', $this->branchB);
        $elsewhereScope = $this->scope($elsewhere);
        self::assertNull($this->repo->findByPublicId($b->publicId, $elsewhereScope), 'another branch cannot even load it');
        self::assertSame([], $this->repo->forPerson($b->personId, $elsewhereScope));
        try {
            $this->service->changeStatus($b, 'quoted', $elsewhere, $b->recordVersion);
            self::fail('outside the booking branch');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        // the tours desk role runs bookings end to end in its branch
        $agent = $this->actor('travel');
        $mine = $this->book($agent, ['total_amount' => '100', 'currency' => 'inr'], $this->customer('Agent booking'));
        self::assertSame('quoted', $this->service->changeStatus($mine, 'quoted', $agent, $mine->recordVersion)->status);
    }

    // ---- read side -------------------------------------------------

    public function test_register_search_filters_sorting_and_counts(): void
    {
        $actor = $this->actor();
        $dubai = $this->package($actor, 'Dubai Discovery');
        $kerala = $this->package($actor, 'Kerala Backwaters', '18000');

        $phone = $this->phone();
        $a = $this->service->create($this->customer('Asha Rao', $phone), $this->trip(['package' => $dubai->publicId, 'travel_date' => gmdate('Y-m-d', strtotime('+10 days'))]), $actor);
        $b = $this->book($actor, ['package' => $kerala->publicId], $this->customer('Ravi Kumar'));
        $c = $this->book($actor, ['total_amount' => '500', 'currency' => 'inr', 'travel_date' => gmdate('Y-m-d', strtotime('+40 days'))], $this->customer('Meena Iyer'));
        $this->service->changeStatus($c, 'cancelled', $actor, $c->recordVersion, 'Not going');
        $scope = $this->scope();

        $total = fn (array $q): int => $this->repo->paginate(ListQuery::of($q), $scope)->total;
        self::assertSame(3, $total(['search' => self::PREFIX]));
        self::assertSame(1, $total(['search' => 'Ravi']), 'by customer');
        self::assertSame(1, $total(['search' => substr($phone, 2, 5)]), 'by phone fragment');
        self::assertSame(1, $total(['search' => $b->bookingNumber]), 'by booking number');
        self::assertSame(1, $total(['search' => 'Kerala']), 'by package name');
        self::assertSame(0, $total(['search' => 'nobody-at-all']));

        self::assertSame(1, $total(['filters' => ['status' => 'cancelled']]));
        self::assertSame(2, $total(['filters' => ['status' => 'inquiry']]));
        self::assertSame(1, $total(['filters' => ['package' => $dubai->publicId]]));
        self::assertSame(1, $total(['filters' => ['when' => 'upcoming']]), 'open + dated in the future (the cancelled one is excluded)');
        self::assertSame(1, $total(['filters' => ['when' => 'undated']]));

        $priciest = $this->repo->paginate(ListQuery::of(['search' => self::PREFIX, 'sort' => 'amount', 'direction' => 'desc']), $scope);
        self::assertSame($a->id, $priciest->items[0]->id, 'the 90,000 Dubai booking is the priciest');
        self::assertGreaterThanOrEqual((float) $priciest->items[1]->totalAmount, (float) $priciest->items[0]->totalAmount);

        $counts = $this->repo->statusCounts($scope);
        self::assertSame(['inquiry', 'quoted', 'confirmed', 'travelling', 'completed', 'cancelled'], array_keys($counts));
        self::assertGreaterThanOrEqual(2, $counts['inquiry']);
        self::assertGreaterThanOrEqual(1, $counts['cancelled']);
    }
}
