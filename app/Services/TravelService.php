<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScope;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Domain\StatusMachine;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\DepartureRecord;
use App\Models\FlightBooking;
use App\Models\Placement;
use App\Models\TravelProfile;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\ApplicationRepository;
use App\Repositories\DepartureRepository;
use App\Repositories\FlightRepository;
use App\Repositories\PlacementRepository;
use App\Repositories\TravelProfileRepository;
use App\Support\Db;
use App\Support\Ulid;

/**
 * The recruitment travel chain: flight bookings → departure → arrival → placement.
 *
 * The application pipeline follows the tickets automatically (via
 * ApplicationService::advance, in the same transaction):
 *
 *   visa_approved ─ first live flight ─▶ ticket_pending ─ a ticketed flight ─▶ ticket_booked
 *   ticket_booked ─ record departure ─▶ departed ─ confirm arrival + place ─▶ placed
 *
 * `reconcile()` is the single rule for the ticketing half, so creating, editing,
 * re-ticketing or cancelling a flight all leave the application in the right
 * state — including sending it back to ticket_pending when its only ticket is
 * cancelled. The travel profile's `readiness` mirrors the same steps.
 */
final class TravelService
{
    /** Application statuses in which flights may be booked or changed. */
    public const TICKETING = ['visa_approved', 'ticket_pending', 'ticket_booked'];

    public function __construct(
        private readonly Db $db,
        private readonly FlightRepository $flights,
        private readonly DepartureRepository $departures,
        private readonly PlacementRepository $placements,
        private readonly TravelProfileRepository $profiles,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationService $applicationService,
        private readonly StatusMachine $statuses,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
        private readonly NotificationService $notifications,
    ) {
    }

    // ---- read model ------------------------------------------------

    /**
     * Everything the travel card on an application needs. The caller has already authorized the application.
     *
     * @return array{flights:list<FlightBooking>,departure:?DepartureRecord,placement:?Placement,profile:?TravelProfile}
     */
    public function panel(Application $app): array
    {
        return [
            'flights'   => $this->flights->forApplication($app->id),
            'departure' => $this->departures->forApplication($app->id),
            'placement' => $this->placements->forApplication($app->id),
            'profile'   => $this->profiles->forCandidate($app->candidateId),
        ];
    }

    // ---- flights ---------------------------------------------------

    /** @param array{status:string,pnr:?string,airline:?string,flight_number:?string,departure_airport:?string,arrival_airport:?string,departure_at:?string,arrival_at:?string,baggage_allowance:?string,ticket_price:?string,currency:?string,notes:?string} $data from TravelValidator::flight() */
    public function bookFlight(Application $app, array $data, User $actor): FlightBooking
    {
        $this->requireOn($app, 'travel.tickets.manage', $actor);
        if (!in_array($app->status, self::TICKETING, true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Tickets can be booked once the visa is approved and until the candidate departs.', []);
        }
        if ($this->flights->countLiveFor($app->id) > 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This application already has a live flight. Change or cancel it first.', []);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($app, $data, $actor, $scope): FlightBooking {
            $id = $this->flights->create([
                'public_id'         => Ulid::generate(),
                'candidate_id'      => $app->candidateId,
                'application_id'    => $app->id,
                'pnr'               => $data['pnr'],
                'airline'           => $data['airline'],
                'flight_number'     => $data['flight_number'],
                'departure_airport' => $data['departure_airport'],
                'arrival_airport'   => $data['arrival_airport'],
                'departure_at'      => $data['departure_at'],
                'arrival_at'        => $data['arrival_at'],
                'baggage_allowance' => $data['baggage_allowance'],
                'ticket_price'      => $data['ticket_price'],
                'currency'          => $data['currency'],
                'status'            => $data['status'],
                'notes'             => $data['notes'],
                'created_by'        => $actor->id,
            ]);
            $this->reconcile($app->id, $actor);
            $this->audit->log('created', 'travel', 'flight_booking', $id, null, [
                'application_id' => $app->id, 'status' => $data['status'], 'pnr' => $data['pnr'], 'departure_at' => $data['departure_at'],
            ], null, $actor);

            return $this->reloadFlight($id, $scope);
        });
    }

    /**
     * Edit the itinerary of a live flight. The status is changed separately.
     *
     * @param array{status:string,pnr:?string,airline:?string,flight_number:?string,departure_airport:?string,arrival_airport:?string,departure_at:?string,arrival_at:?string,baggage_allowance:?string,ticket_price:?string,currency:?string,notes:?string} $data
     */
    public function updateFlight(FlightBooking $flight, array $data, User $actor): FlightBooking
    {
        $this->requireFlightEdit($flight, $actor);
        if (!$flight->isLive()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A cancelled or flown flight cannot be edited.', []);
        }
        if ($flight->isTicketed() && ($data['pnr'] === null || $data['departure_at'] === null)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A ticketed flight keeps its booking reference (PNR) and departure time.', []);
        }

        $scope = $this->scopes->resolve($actor);
        $set = array_diff_key($data, ['status' => true]);

        return $this->db->transaction(function () use ($flight, $set, $actor, $scope): FlightBooking {
            $this->guardedFlight($flight, $set, [$flight->status]);
            $this->audit->log('updated', 'travel', 'flight_booking', $flight->id,
                ['pnr' => $flight->pnr, 'departure_at' => $flight->departureAt],
                ['pnr' => $set['pnr'], 'departure_at' => $set['departure_at']], null, $actor);

            return $this->reloadFlight($flight->id, $scope);
        });
    }

    public function changeFlightStatus(FlightBooking $flight, string $to, User $actor): FlightBooking
    {
        $this->requireFlightEdit($flight, $actor);
        if ($to === 'flown') {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A flight becomes “flown” when you record the departure.', []);
        }
        $this->statuses->assert('flight', $flight->status, $to);
        if (in_array($to, FlightBooking::TICKETED, true) && ($flight->pnr === null || $flight->departureAt === null)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Add the booking reference (PNR) and departure time before marking the ticket as booked or issued.', []);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($flight, $to, $actor, $scope): FlightBooking {
            $this->guardedFlight($flight, ['status' => $to], [$flight->status]);
            if ($flight->applicationId !== null) {
                $this->reconcile($flight->applicationId, $actor);
            }
            $this->audit->log('status_changed', 'travel', 'flight_booking', $flight->id, ['status' => $flight->status], ['status' => $to], null, $actor);

            return $this->reloadFlight($flight->id, $scope);
        });
    }

    // ---- departure / arrival / placement ---------------------------

    /** @param array{at:string,notes:?string} $data from TravelValidator::movement() */
    public function recordDeparture(Application $app, array $data, User $actor): DepartureRecord
    {
        $this->requireOn($app, 'travel.departure.manage', $actor);
        if ($app->status !== 'ticket_booked') {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A departure can only be recorded for an application whose ticket is booked.', []);
        }
        $flight = $this->flights->currentTicketed($app->id);
        if ($flight === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'There is no booked or issued ticket to depart on.', []);
        }
        if ($this->departures->forApplication($app->id) !== null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The departure has already been recorded.', []);
        }

        return $this->db->transaction(function () use ($app, $flight, $data, $actor): DepartureRecord {
            $this->guardedFlight($flight, ['status' => 'flown'], FlightBooking::TICKETED);
            $id = $this->departures->create([
                'candidate_id'      => $app->candidateId,
                'application_id'    => $app->id,
                'flight_booking_id' => $flight->id,
                'departed_at'       => $data['at'],
                'notes'             => $data['notes'],
            ]);
            $this->applicationService->advance($app->id, 'departed', $actor, 'Departed');
            $this->profiles->save($app->candidateId, ['readiness' => 'departed', 'application_id' => $app->id]);
            $this->audit->log('departed', 'travel', 'departure_record', $id, null, ['application_id' => $app->id, 'departed_at' => $data['at'], 'flight' => $flight->pnr], $data['notes'], $actor);

            return $this->reloadDeparture($app->id);
        });
    }

    /** @param array{at:string,notes:?string} $data from TravelValidator::movement() */
    public function confirmArrival(Application $app, array $data, User $actor): DepartureRecord
    {
        $this->requireOn($app, 'travel.departure.manage', $actor);
        $departure = $this->departures->forApplication($app->id);
        if ($app->status !== 'departed' || $departure === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Arrival can only be confirmed after the departure has been recorded.', []);
        }
        if ($departure->hasArrived()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The arrival has already been confirmed.', []);
        }
        if ($departure->departedAt !== null && $data['at'] < $departure->departedAt) {
            throw new ValidationException(['arrived_at' => ['The candidate cannot arrive before they departed.']]);
        }

        $updated = $this->db->transaction(function () use ($app, $departure, $data, $actor): DepartureRecord {
            if ($this->departures->confirmArrival($departure->id, $data['at'], $actor->id, $data['notes']) === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The arrival has already been confirmed.', []);
            }
            $this->profiles->save($app->candidateId, ['readiness' => 'arrived', 'application_id' => $app->id]);
            $this->audit->log('arrived', 'travel', 'departure_record', $departure->id, null, ['arrived_at' => $data['at']], $data['notes'], $actor);

            return $this->reloadDeparture($app->id);
        });

        $this->notifyOwner($app, $actor, 'arrival_confirmed', "Arrived: {$app->candidateName}", "{$app->applicationNumber} · ready to be placed with {$app->employerName}");

        return $updated;
    }

    /** @param array{placed_on:string,monthly_salary:?string,currency:?string,contract_end:?string} $data from TravelValidator::placement() */
    public function place(Application $app, array $data, User $actor): Placement
    {
        $this->requireOn($app, 'travel.placement.manage', $actor);
        $departure = $this->departures->forApplication($app->id);
        if ($app->status !== 'departed' || $departure === null || !$departure->hasArrived()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Confirm the candidate’s arrival before recording the placement.', []);
        }
        if ($this->placements->forApplication($app->id) !== null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This application already has a placement.', []);
        }
        if ($departure->departedAt !== null && $data['placed_on'] < substr($departure->departedAt, 0, 10)) {
            throw new ValidationException(['placed_on' => ['The placement cannot be dated before the departure.']]);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($app, $departure, $data, $actor, $scope): Placement {
            $id = $this->placements->create([
                'public_id'      => Ulid::generate(),
                'candidate_id'   => $app->candidateId,
                'application_id' => $app->id,
                'employer_id'    => $app->employerId,
                'job_id'         => $app->jobId,
                'branch_id'      => $app->branchId,
                'placed_on'      => $data['placed_on'],
                'monthly_salary' => $data['monthly_salary'],
                'currency'       => $data['currency'],
                'contract_end'   => $data['contract_end'],
                'status'         => 'active',
            ]);
            $this->departures->markPlaced($departure->id, gmdate('Y-m-d H:i:s'));
            $this->applicationService->advance($app->id, 'placed', $actor, 'Placed with ' . $app->employerName);
            $this->audit->log('placed', 'travel', 'placement', $id, null, [
                'application_id' => $app->id, 'employer_id' => $app->employerId, 'placed_on' => $data['placed_on'],
            ], null, $actor);

            $placement = $this->placements->findById($id, $scope);
            if ($placement === null) {
                throw new \RuntimeException('Placement vanished mid-operation.');
            }

            return $placement;
        });
    }

    /** Close an active placement as completed / terminated / absconded. */
    public function endPlacement(Placement $placement, string $to, ?string $reason, User $actor): Placement
    {
        if (!$this->gate->forUser($actor)->allows('edit', $placement)) {
            throw AuthorizationException::forPermission('travel.placement.manage');
        }
        $this->statuses->assert('placement', $placement->status, $to);
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;
        if (in_array($to, ['terminated', 'absconded'], true) && $reason === null) {
            throw new ValidationException(['reason' => ['Please give a reason.']]);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($placement, $to, $reason, $actor, $scope): Placement {
            if ($this->placements->end($placement->id, $to) === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This placement changed just now. Please review it and try again.', []);
            }
            $this->audit->log('status_changed', 'travel', 'placement', $placement->id, ['status' => $placement->status], ['status' => $to], $reason, $actor);

            $fresh = $this->placements->findById($placement->id, $scope);
            if ($fresh === null) {
                throw new \RuntimeException('Placement vanished mid-operation.');
            }

            return $fresh;
        });
    }

    // ---- profile ---------------------------------------------------

    /** @param array{preferred_departure_city:?string,notes:?string} $data from TravelValidator::profile() */
    public function saveProfile(Candidate $candidate, ?Application $app, array $data, User $actor): TravelProfile
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows('travel.profile.manage') || !$g->allows('view', $candidate)) {
            throw AuthorizationException::forPermission('travel.profile.manage');
        }
        if ($app !== null && $app->candidateId !== $candidate->id) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That application belongs to a different candidate.', []);
        }

        $existing = $this->profiles->forCandidate($candidate->id);
        $set = $data + ($app !== null ? ['application_id' => $app->id] : []);
        if ($existing === null) {
            $set['readiness'] = 'planning';
        }

        return $this->db->transaction(function () use ($candidate, $existing, $set, $actor): TravelProfile {
            $this->profiles->save($candidate->id, $set);
            $this->audit->log($existing === null ? 'created' : 'updated', 'travel', 'travel_profile', $existing?->id ?? $candidate->id,
                $existing !== null ? ['preferred_departure_city' => $existing->preferredDepartureCity] : null,
                ['preferred_departure_city' => $set['preferred_departure_city']], null, $actor);

            $profile = $this->profiles->forCandidate($candidate->id);
            if ($profile === null) {
                throw new \RuntimeException('Travel profile vanished mid-operation.');
            }

            return $profile;
        });
    }

    // ---- internals -------------------------------------------------

    /**
     * Bring the application's ticketing status in line with its flights (see the class doc).
     * Must run inside the caller's transaction.
     */
    private function reconcile(int $applicationId, User $actor): void
    {
        $scope = $this->scopes->resolve($actor);
        $app = $this->applications->findById($applicationId, $scope);
        if ($app === null || !in_array($app->status, self::TICKETING, true)) {
            return;
        }

        $live = $this->flights->countLiveFor($applicationId);
        $ticketed = $this->flights->countLiveFor($applicationId, true);
        $status = $app->status;

        if ($status === 'visa_approved' && $live > 0) {
            $this->applicationService->advance($applicationId, 'ticket_pending', $actor, 'Flight being arranged');
            $status = 'ticket_pending';
        }
        if ($status === 'ticket_pending' && $ticketed > 0) {
            $this->applicationService->advance($applicationId, 'ticket_booked', $actor, 'Ticket booked');
            $status = 'ticket_booked';
        } elseif ($status === 'ticket_booked' && $ticketed === 0) {
            $this->applicationService->advance($applicationId, 'ticket_pending', $actor, 'No ticketed flight remains');
            $status = 'ticket_pending';
        }

        $this->profiles->save($app->candidateId, [
            'readiness'      => $status === 'ticket_booked' ? 'ticket_booked' : 'ticket_pending',
            'application_id' => $applicationId,
        ]);
    }

    private function requireOn(Application $app, string $permission, User $actor): void
    {
        $g = $this->gate->forUser($actor);
        if (!$g->allows($permission) || !$g->allows('view', $app)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function requireFlightEdit(FlightBooking $flight, User $actor): void
    {
        if (!$this->gate->forUser($actor)->allows('edit', $flight)) {
            throw AuthorizationException::forPermission('travel.tickets.manage');
        }
    }

    /**
     * @param array<string,mixed> $set
     * @param list<string> $from
     */
    private function guardedFlight(FlightBooking $flight, array $set, array $from): void
    {
        if ($this->flights->updateFrom($flight->id, $set, $from) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This flight changed just now. Please review it and try again.', []);
        }
    }

    private function reloadFlight(int $id, BranchScope $scope): FlightBooking
    {
        $flight = $this->flights->findById($id, $scope);
        if ($flight === null) {
            throw new \RuntimeException('Flight booking vanished mid-operation.');
        }

        return $flight;
    }

    private function reloadDeparture(int $applicationId): DepartureRecord
    {
        $d = $this->departures->forApplication($applicationId);
        if ($d === null) {
            throw new \RuntimeException('Departure record vanished mid-operation.');
        }

        return $d;
    }

    private function notifyOwner(Application $app, User $actor, string $type, string $title, string $body): void
    {
        if ($app->assignedTo === null || $app->assignedTo === $actor->id) {
            return;
        }
        $this->notifications->notify(
            userId: $app->assignedTo,
            type: $type,
            title: $title,
            body: $body,
            linkType: 'application',
            linkId: $app->id,
            linkFragment: 'travel',
        );
    }
}
