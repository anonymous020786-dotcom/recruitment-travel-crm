<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\FlightBooking;
use App\Models\User;
use App\Repositories\ApplicationHistoryRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\DepartureRepository;
use App\Repositories\FlightRepository;
use App\Repositories\PlacementRepository;
use App\Repositories\TravelProfileRepository;
use App\Repositories\TravelRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\TravelService;
use App\Support\Hash;
use App\Support\ListQuery;
use App\Support\Ulid;
use App\Validators\JobValidator;
use App\Validators\TravelValidator;
use Tests\Support\DbTestCase;

final class TravelServiceTest extends DbTestCase
{
    private TravelService $service;
    private ApplicationService $applications;
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
        $this->service = $this->app->get(TravelService::class);
        $this->applications = $this->app->get(ApplicationService::class);
        $this->branchA = $this->branch('TX-A');
        $this->branchB = $this->branch('TX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'TX-%')";
        $this->db->affectingStatement("DELETE FROM placements WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM departure_records WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM flight_bookings WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM travel_profiles WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('applications', 'travel', 'jobs', 'employers', 'leads', 'candidates')");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'TX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(job|employer|lead|candidate|application):'");
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
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'tx_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function scope(): \App\Auth\BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($this->app->get(\App\Repositories\UserRepository::class)->findById($this->userIds[0]));
    }

    private function candidate(User $actor): Candidate
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'Cand', 'phone' => '94' . random_int(10000000, 99999999), 'priority' => 'medium'], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    /** An application walked (legally) up to $target. */
    private function applicationAt(User $actor, Candidate $c, string $target = 'visa_approved'): Application
    {
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);
        $app = $this->applications->create($c, $job, $actor);

        foreach (['shortlisted', 'interview_scheduled', 'interview_completed', 'selected', 'offer_received', 'offer_accepted', 'medical_pending', 'medical_completed', 'visa_processing', 'visa_approved'] as $step) {
            if ($app->status === $target) {
                break;
            }
            $app = $this->applications->changeStatus($app, $step, $actor, $app->recordVersion);
        }

        return $app;
    }

    private function fresh(Application $a): Application
    {
        return $this->app->get(ApplicationRepository::class)->findById($a->id, $this->scope());
    }

    /** @return array<string,mixed> validated flight data */
    private function flight(string $status = 'planned', array $extra = []): array
    {
        return (new TravelValidator())->flight($extra + [
            'status' => $status, 'pnr' => 'ABC123', 'airline' => 'Air India', 'flight_number' => 'ai 995',
            'departure_airport' => 'del', 'arrival_airport' => 'dxb',
            'departure_at' => gmdate('Y-m-d\TH:i', strtotime('+5 days')), 'arrival_at' => gmdate('Y-m-d\TH:i', strtotime('+5 days 4 hours')),
        ]);
    }

    private function movement(string $field, ?string $at = null): array
    {
        return (new TravelValidator())->movement($at !== null ? [$field => $at] : [], $field);
    }

    /** @return list<string> the "to" statuses of the application's history, oldest first (the repository returns newest first) */
    private function historyOf(Application $app): array
    {
        return array_reverse(array_map(static fn (array $h): string => $h['to'], $this->app->get(ApplicationHistoryRepository::class)->forApplication($app->id)));
    }

    private function profile(Candidate $c): ?\App\Models\TravelProfile
    {
        return $this->app->get(TravelProfileRepository::class)->forCandidate($c->id);
    }

    /** An application ticketed and departed, ready for arrival. */
    private function departed(User $actor): array
    {
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c);
        $this->service->bookFlight($app, $this->flight('issued'), $actor);
        $this->service->recordDeparture($this->fresh($app), $this->movement('departed_at', gmdate('Y-m-d\TH:i', strtotime('-1 hour'))), $actor);

        return [$c, $this->fresh($app)];
    }

    // ---- flights ---------------------------------------------------

    public function test_first_flight_moves_a_visa_approved_application_to_ticket_pending(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c);
        self::assertSame('visa_approved', $app->status);

        $f = $this->service->bookFlight($app, $this->flight('planned'), $actor);

        self::assertSame('planned', $f->status);
        self::assertSame('DEL → DXB', $f->route());
        self::assertSame('AI 995', $f->flightNumber, 'flight number is normalised to upper case');
        self::assertSame('ticket_pending', $this->fresh($app)->status);
        self::assertSame('ticket_pending', $this->profile($c)?->readiness);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='travel' AND action='created' AND record_id = ?", [$f->id]));
    }

    public function test_an_issued_ticket_walks_the_application_to_ticket_booked(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c);

        $f = $this->service->bookFlight($app, $this->flight('issued'), $actor);

        self::assertTrue($f->isTicketed());
        self::assertSame('ticket_booked', $this->fresh($app)->status);
        self::assertSame(['ticket_pending', 'ticket_booked'], array_slice($this->historyOf($app), -2), 'both steps are in the history');
        self::assertSame('ticket_booked', $this->profile($c)?->readiness);
    }

    public function test_marking_a_planned_flight_booked_advances_and_cancelling_the_only_ticket_steps_back(): void
    {
        $actor = $this->actor();
        $app = $this->applicationAt($actor, $this->candidate($actor));
        $f = $this->service->bookFlight($app, $this->flight('planned'), $actor);

        $f = $this->service->changeFlightStatus($f, 'booked', $actor);
        self::assertSame('ticket_booked', $this->fresh($app)->status);

        $f = $this->service->changeFlightStatus($f, 'cancelled', $actor);
        self::assertSame('cancelled', $f->status);
        self::assertSame('ticket_pending', $this->fresh($app)->status, 'no ticket left → back to ticket pending');

        // a new flight can now be booked
        $again = $this->service->bookFlight($this->fresh($app), $this->flight('issued'), $actor);
        self::assertSame('issued', $again->status);
        self::assertSame('ticket_booked', $this->fresh($app)->status);
    }

    public function test_marking_a_ticketed_flight_changed_drops_the_application_back_until_it_is_re_ticketed(): void
    {
        $actor = $this->actor();
        $app = $this->applicationAt($actor, $this->candidate($actor));
        $f = $this->service->bookFlight($app, $this->flight('issued'), $actor);

        $f = $this->service->changeFlightStatus($f, 'changed', $actor);
        self::assertSame('ticket_pending', $this->fresh($app)->status);

        $this->service->changeFlightStatus($f, 'issued', $actor);
        self::assertSame('ticket_booked', $this->fresh($app)->status);
    }

    public function test_flight_rules(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);

        $early = $this->applicationAt($actor, $c, 'shortlisted');
        try {
            $this->service->bookFlight($early, $this->flight(), $actor);
            self::fail('no tickets before the visa is approved');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $app = $this->applicationAt($actor, $this->candidate($actor));
        $f = $this->service->bookFlight($app, $this->flight(), $actor);
        try {
            $this->service->bookFlight($this->fresh($app), $this->flight(), $actor);
            self::fail('one live flight per application');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        // flown only through the departure; cancelled is final
        try {
            $this->service->changeFlightStatus($f, 'flown', $actor);
            self::fail('flown is set by recording the departure');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
        $f = $this->service->changeFlightStatus($f, 'cancelled', $actor);
        $this->expectException(DomainRuleException::class);
        $this->service->changeFlightStatus($f, 'booked', $actor);
    }

    public function test_a_flight_without_pnr_or_departure_time_cannot_be_ticketed(): void
    {
        $actor = $this->actor();
        $app = $this->applicationAt($actor, $this->candidate($actor));
        $bare = $this->service->bookFlight($app, $this->flight('planned', ['pnr' => '', 'departure_at' => '']), $actor);

        $this->expectException(DomainRuleException::class);
        $this->service->changeFlightStatus($bare, 'issued', $actor);
    }

    public function test_validator_rejects_bad_flight_input(): void
    {
        $v = new TravelValidator();
        $bad = [
            'booked without a PNR'      => ['status' => 'booked', 'pnr' => '', 'departure_at' => '2030-01-01T10:00'],
            'booked without a time'      => ['status' => 'issued', 'pnr' => 'ABC123'],
            'arrives before it departs'  => ['departure_at' => '2030-01-01T10:00', 'arrival_at' => '2030-01-01T09:00'],
            'airport code'               => ['departure_airport' => 'DELHI'],
            'price without currency'     => ['ticket_price' => '450.00'],
            'negative price'             => ['ticket_price' => '-1', 'currency' => 'INR'],
            'garbage date'               => ['departure_at' => 'tomorrow-ish'],
            'short PNR'                  => ['pnr' => 'AB'],
        ];
        foreach ($bad as $why => $input) {
            try {
                $v->flight($input);
                self::fail("accepted: {$why}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_update_flight_edits_the_itinerary_but_protects_a_ticket(): void
    {
        $actor = $this->actor();
        $app = $this->applicationAt($actor, $this->candidate($actor));
        $f = $this->service->bookFlight($app, $this->flight('issued'), $actor);

        $f = $this->service->updateFlight($f, $this->flight('planned', ['airline' => 'Emirates', 'flight_number' => 'EK 511', 'baggage_allowance' => '30 kg', 'ticket_price' => '450.50', 'currency' => 'aed']), $actor);
        self::assertSame('Emirates', $f->airline);
        self::assertSame('EK 511', $f->flightNumber);
        self::assertSame('450.50', $f->ticketPrice);
        self::assertSame('AED', $f->currency);
        self::assertSame('issued', $f->status, 'editing does not touch the status');

        try {
            $this->service->updateFlight($f, $this->flight('planned', ['pnr' => '']), $actor);
            self::fail('a ticketed flight keeps its PNR');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $cancelled = $this->service->changeFlightStatus($f, 'cancelled', $actor);
        $this->expectException(DomainRuleException::class);
        $this->service->updateFlight($cancelled, $this->flight(), $actor);
    }

    // ---- departure / arrival / placement ---------------------------

    public function test_recording_the_departure_flies_the_flight_and_departs_the_application(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c);
        $f = $this->service->bookFlight($app, $this->flight('booked'), $actor);

        $d = $this->service->recordDeparture($this->fresh($app), $this->movement('departed_at', gmdate('Y-m-d\TH:i', strtotime('-2 hours'))), $actor);

        self::assertNotNull($d->departedAt);
        self::assertFalse($d->hasArrived());
        self::assertSame($f->id, $d->flightBookingId);
        self::assertSame('departed', $this->fresh($app)->status);
        self::assertSame('flown', $this->app->get(FlightRepository::class)->findById($f->id, $this->scope())->status);
        self::assertSame('departed', $this->profile($c)?->readiness);

        try {
            $this->service->recordDeparture($this->fresh($app), $this->movement('departed_at'), $actor);
            self::fail('departure can only be recorded once');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
    }

    public function test_departure_needs_a_ticketed_application_and_a_time_that_is_not_in_the_future(): void
    {
        $actor = $this->actor();
        $app = $this->applicationAt($actor, $this->candidate($actor));
        $this->service->bookFlight($app, $this->flight('planned'), $actor);

        try {
            $this->service->recordDeparture($this->fresh($app), $this->movement('departed_at'), $actor);
            self::fail('a planned (unticketed) flight cannot depart');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        $this->movement('departed_at', gmdate('Y-m-d\TH:i', strtotime('+3 days')));
    }

    public function test_arrival_is_confirmed_once_and_never_before_departure(): void
    {
        $actor = $this->actor();
        [$c, $app] = $this->departed($actor);

        try {
            $this->service->confirmArrival($app, $this->movement('arrived_at', '2020-01-01T10:00'), $actor);
            self::fail('cannot arrive before departing');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $d = $this->service->confirmArrival($app, $this->movement('arrived_at'), $actor);
        self::assertTrue($d->hasArrived());
        self::assertSame($actor->id, $d->arrivalConfirmedBy);
        self::assertSame('departed', $this->fresh($app)->status, 'the application stays departed until it is placed');
        self::assertSame('arrived', $this->profile($c)?->readiness);

        $this->expectException(DomainRuleException::class);
        $this->service->confirmArrival($app, $this->movement('arrived_at'), $actor);
    }

    public function test_placement_closes_the_application_as_placed(): void
    {
        $actor = $this->actor();
        [$c, $app] = $this->departed($actor);

        try {
            $this->service->place($app, (new TravelValidator())->placement(['placed_on' => gmdate('Y-m-d')]), $actor);
            self::fail('arrival must be confirmed first');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $this->service->confirmArrival($app, $this->movement('arrived_at'), $actor);
        $p = $this->service->place($app, (new TravelValidator())->placement([
            'placed_on' => gmdate('Y-m-d'), 'monthly_salary' => '2500', 'currency' => 'aed', 'contract_end' => gmdate('Y-m-d', strtotime('+2 years')),
        ]), $actor);

        self::assertSame('active', $p->status);
        self::assertSame($app->employerId, $p->employerId, 'the employer and job come from the application');
        self::assertSame($app->jobId, $p->jobId);
        self::assertSame($this->branchA, $p->branchId);
        self::assertSame('2500.00', $p->monthlySalary);
        self::assertSame('AED', $p->currency);

        $done = $this->fresh($app);
        self::assertSame('placed', $done->status);
        self::assertTrue($done->isTerminal());
        self::assertNotNull($done->closedAt);
        self::assertNotNull($this->app->get(DepartureRepository::class)->forApplication($app->id)?->placementConfirmedAt);

        try {
            $this->service->place($done, (new TravelValidator())->placement(['placed_on' => gmdate('Y-m-d')]), $actor);
            self::fail('one placement per application');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
    }

    public function test_placement_validation(): void
    {
        $v = new TravelValidator();
        foreach ([
            'future date'          => ['placed_on' => gmdate('Y-m-d', strtotime('+5 days'))],
            'contract before start' => ['placed_on' => gmdate('Y-m-d'), 'contract_end' => gmdate('Y-m-d', strtotime('-1 day'))],
            'salary no currency'   => ['placed_on' => gmdate('Y-m-d'), 'monthly_salary' => '100'],
            'bad date'             => ['placed_on' => '31/12/2026'],
        ] as $why => $input) {
            try {
                $v->placement($input);
                self::fail("accepted: {$why}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_a_placement_cannot_be_dated_before_the_departure(): void
    {
        $actor = $this->actor();
        [, $app] = $this->departed($actor);
        $this->service->confirmArrival($app, $this->movement('arrived_at'), $actor);

        $this->expectException(ValidationException::class);
        $this->service->place($app, (new TravelValidator())->placement(['placed_on' => gmdate('Y-m-d', strtotime('-30 days'))]), $actor);
    }

    public function test_ending_a_placement(): void
    {
        $actor = $this->actor();
        [, $app] = $this->departed($actor);
        $this->service->confirmArrival($app, $this->movement('arrived_at'), $actor);
        $p = $this->service->place($app, (new TravelValidator())->placement(['placed_on' => gmdate('Y-m-d')]), $actor);

        try {
            $this->service->endPlacement($p, 'absconded', '  ', $actor);
            self::fail('absconded needs a reason');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $ended = $this->service->endPlacement($p, 'terminated', 'Visa cancelled by employer', $actor);
        self::assertSame('terminated', $ended->status);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='travel' AND action='status_changed' AND record_id = ?", [$p->id]));

        $this->expectException(DomainRuleException::class);
        $this->service->endPlacement($ended, 'completed', null, $actor);
    }

    public function test_arrival_notifies_the_application_owner_only(): void
    {
        $manager = $this->actor();
        $owner = $this->actor('recruitment');
        $c = $this->candidate($manager);
        $this->db->affectingStatement('UPDATE candidates SET assigned_counselor = ? WHERE id = ?', [$owner->id, $c->id]);
        $app = $this->applicationAt($manager, $this->app->get(\App\Repositories\CandidateRepository::class)->findById($c->id, $this->scope()));
        self::assertSame($owner->id, $app->assignedTo);

        $this->service->bookFlight($app, $this->flight('issued'), $manager);
        $this->service->recordDeparture($this->fresh($app), $this->movement('departed_at', gmdate('Y-m-d\TH:i', strtotime('-1 hour'))), $manager);
        $this->service->confirmArrival($this->fresh($app), $this->movement('arrived_at'), $manager);

        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'arrival_confirmed'", [$owner->id]));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'arrival_confirmed'", [$manager->id]));
    }

    // ---- profile ---------------------------------------------------

    public function test_saving_a_travel_profile_creates_then_updates_one_row_and_keeps_readiness(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c);
        $v = new TravelValidator();

        $p = $this->service->saveProfile($c, $app, $v->profile(['preferred_departure_city' => 'Mumbai', 'notes' => 'Vegetarian meal']), $actor);
        self::assertSame('planning', $p->readiness);
        self::assertSame('Mumbai', $p->preferredDepartureCity);

        $this->service->bookFlight($app, $this->flight('issued'), $actor);
        $p = $this->service->saveProfile($c, $app, $v->profile(['preferred_departure_city' => 'Delhi']), $actor);

        self::assertSame('Delhi', $p->preferredDepartureCity);
        self::assertSame('ticket_booked', $p->readiness, 'saving the form does not reset readiness');
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM travel_profiles WHERE candidate_id = ?', [$c->id]));
    }

    // ---- permissions -----------------------------------------------

    public function test_permissions_and_branch_scope(): void
    {
        $manager = $this->actor();
        $c = $this->candidate($manager);
        $app = $this->applicationAt($manager, $c);
        $f = $this->service->bookFlight($app, $this->flight('issued'), $manager);

        foreach (['read_only', 'counselor'] as $role) {
            $denied = $this->actor($role);
            foreach ([
                fn () => $this->service->bookFlight($app, $this->flight(), $denied),
                fn () => $this->service->changeFlightStatus($f, 'cancelled', $denied),
                fn () => $this->service->updateFlight($f, $this->flight(), $denied),
                fn () => $this->service->recordDeparture($app, $this->movement('departed_at'), $denied),
                fn () => $this->service->saveProfile($c, $app, (new TravelValidator())->profile([]), $denied),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail("{$role} must not manage travel");
                } catch (AuthorizationException) {
                    self::assertTrue(true);
                }
            }
        }

        // the visa role has travel.* but only inside its own branch
        $elsewhere = $this->actor('visa', $this->branchB);
        $this->expectException(AuthorizationException::class);
        $this->service->recordDeparture($app, $this->movement('departed_at'), $elsewhere);
    }

    public function test_the_visa_role_can_run_the_whole_chain(): void
    {
        $manager = $this->actor();
        $agent = $this->actor('visa');
        $app = $this->applicationAt($manager, $this->candidate($manager));

        $this->service->bookFlight($app, $this->flight('issued'), $agent);
        $this->service->recordDeparture($this->fresh($app), $this->movement('departed_at', gmdate('Y-m-d\TH:i', strtotime('-1 hour'))), $agent);
        $this->service->confirmArrival($this->fresh($app), $this->movement('arrived_at'), $agent);
        $p = $this->service->place($this->fresh($app), (new TravelValidator())->placement(['placed_on' => gmdate('Y-m-d')]), $agent);

        self::assertSame('placed', $this->fresh($app)->status);
        self::assertSame('active', $p->status);
    }

    // ---- read side -------------------------------------------------

    public function test_pipeline_lists_the_travel_stages_with_counts_search_and_filters(): void
    {
        $actor = $this->actor();
        $repo = $this->app->get(TravelRepository::class);
        $scope = $this->scope();

        $approved = $this->applicationAt($actor, $this->candidate($actor));
        $booked = $this->applicationAt($actor, $this->candidate($actor));
        $this->service->bookFlight($booked, $this->flight('issued', ['pnr' => 'ZZ9PLURAL']), $actor);
        $this->applicationAt($actor, $this->candidate($actor), 'medical_pending'); // not on the travel desk

        $counts = $repo->stageCounts($scope);
        self::assertSame(['visa_approved', 'ticket_pending', 'ticket_booked', 'departed'], array_keys($counts));
        self::assertSame(1, $counts['visa_approved']);
        self::assertSame(1, $counts['ticket_booked']);

        $page = $repo->paginate(ListQuery::of([]), $scope);
        self::assertSame(2, $page->total);

        $byPnr = $repo->paginate(ListQuery::of(['search' => 'ZZ9PLURAL']), $scope);
        self::assertSame(1, $byPnr->total);
        self::assertSame($booked->applicationNumber, $byPnr->items[0]['application_number']);
        self::assertSame('DEL', $byPnr->items[0]['departure_airport']);

        $byName = $repo->paginate(ListQuery::of(['search' => 'Cand']), $scope);
        self::assertSame(2, $byName->total);

        $onlyApproved = $repo->paginate(ListQuery::of(['filters' => ['status' => 'visa_approved']]), $scope);
        self::assertSame(1, $onlyApproved->total);
        self::assertSame($approved->applicationNumber, $onlyApproved->items[0]['application_number']);

        $other = $this->actor('manager', $this->branchB);
        $otherScope = $this->app->get(BranchScopeResolver::class)->resolve($other);
        self::assertSame(0, $repo->paginate(ListQuery::of([]), $otherScope)->total, 'branch scoping');
    }

    public function test_placements_register_search_and_filters(): void
    {
        $actor = $this->actor();
        [, $app] = $this->departed($actor);
        $this->service->confirmArrival($app, $this->movement('arrived_at'), $actor);
        $this->service->place($app, (new TravelValidator())->placement(['placed_on' => gmdate('Y-m-d')]), $actor);
        $repo = $this->app->get(PlacementRepository::class);
        $scope = $this->scope();

        self::assertSame(1, $repo->paginate(ListQuery::of([]), $scope)->total);
        self::assertSame(1, $repo->paginate(ListQuery::of(['search' => 'Cand']), $scope)->total, 'search by candidate');
        self::assertSame(1, $repo->paginate(ListQuery::of(['search' => 'Noor']), $scope)->total, 'search by employer');
        self::assertSame(0, $repo->paginate(ListQuery::of(['search' => 'nobody-at-all']), $scope)->total);
        self::assertSame(1, $repo->paginate(ListQuery::of(['filters' => ['status' => 'active']]), $scope)->total);
        self::assertSame(0, $repo->paginate(ListQuery::of(['filters' => ['status' => 'terminated']]), $scope)->total);
        self::assertSame($app->id, $repo->forApplication($app->id)?->applicationId);
    }

    public function test_the_panel_collects_everything_for_the_application_card(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $app = $this->applicationAt($actor, $c);
        $this->service->bookFlight($app, $this->flight('planned'), $actor);

        $panel = $this->service->panel($this->fresh($app));

        self::assertCount(1, $panel['flights']);
        self::assertInstanceOf(FlightBooking::class, $panel['flights'][0]);
        self::assertNull($panel['departure']);
        self::assertNull($panel['placement']);
        self::assertSame('ticket_pending', $panel['profile']?->readiness);
    }
}
