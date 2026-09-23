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
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\TourBooking;
use App\Models\TourPackage;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\PersonRepository;
use App\Repositories\TourBookingHistoryRepository;
use App\Repositories\TourBookingRepository;
use App\Repositories\TourPackageRepository;
use App\Repositories\UserRepository;
use App\Support\Db;
use App\Support\Sequences;
use App\Support\Ulid;

/**
 * Tour bookings: a customer (a shared `persons` identity, matched by phone / email
 * so the same human never gets a second record) buying a package or a custom trip.
 *
 *   inquiry → quoted → confirmed → travelling → completed        (cancelled from inquiry/quoted/confirmed)
 *
 * Every write is optimistic-locked on `record_version`; every status change is
 * asserted against the `tour_booking` StatusMachine, appended to the history and
 * audited in one transaction. The transitions also carry business gates: a quote
 * needs a price, a confirmation needs a price and a future travel date, a trip can
 * start only on / after its travel date, and a cancellation needs a reason.
 */
final class TourBookingService
{
    private const DEFAULT_CURRENCY = 'INR';

    /** The furthest-ahead time zone: "today" for a customer there may already be tomorrow here. */
    private const TZ_SLACK_HOURS = 14;

    public function __construct(
        private readonly Db $db,
        private readonly TourBookingRepository $bookings,
        private readonly TourBookingHistoryRepository $history,
        private readonly TourPackageRepository $packages,
        private readonly PersonRepository $persons,
        private readonly UserRepository $users,
        private readonly StatusMachine $statuses,
        private readonly Sequences $sequences,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param array{full_name:string,primary_phone:string,email:?string} $customer from TourBookingValidator::customer()
     * @param array<string,mixed> $trip from TourBookingValidator::trip()
     */
    public function create(array $customer, array $trip, User $actor, ?int $branchId = null): TourBooking
    {
        if (!$this->gate->forUser($actor)->allows('tours.bookings.create')) {
            throw AuthorizationException::forPermission('tours.bookings.create');
        }

        $scope = $this->scopes->resolve($actor);
        $branchId ??= $actor->primaryBranchId;
        if ($branchId === null || !$scope->contains($branchId)) {
            throw new ValidationException(['branch' => ['Choose a branch you can book tours for.']]);
        }

        $package = $this->resolvePackage($trip['package'], null);
        $assignee = $trip['assigned_to'] ?? $actor->id;
        $this->assertAssignee($assignee, $branchId);
        [$amount, $currency] = $this->resolveAmount($trip, $package);

        $booking = $this->db->transaction(function () use ($customer, $trip, $actor, $branchId, $package, $assignee, $amount, $currency, $scope): TourBooking {
            $person = $this->persons->findOrCreate($customer);
            if ($this->bookings->hasOpenDuplicate($person['id'], $package?->id, $trip['travel_date'])) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This customer already has an open booking for the same trip and date.', []);
            }

            $id = $this->bookings->create([
                'public_id'       => Ulid::generate(),
                'booking_number'  => $this->sequences->next('tour_booking', 'TB', 6),
                'person_id'       => $person['id'],
                'tour_package_id' => $package?->id,
                'branch_id'       => $branchId,
                'travel_date'     => $trip['travel_date'],
                'return_date'     => $trip['return_date'],
                'adults'          => $trip['adults'],
                'children'        => $trip['children'],
                'total_amount'    => $amount,
                'currency'        => $currency,
                'status'          => 'inquiry',
                'assigned_to'     => $assignee,
                'notes'           => $trip['notes'],
                'created_by'      => $actor->id,
            ]);
            $this->history->append($id, null, 'inquiry', null, $actor->id);
            $this->audit->log('created', 'tours', 'tour_booking', $id, null, [
                'person_id' => $person['id'], 'package_id' => $package?->id, 'travel_date' => $trip['travel_date'], 'total_amount' => $amount,
            ], null, $actor);

            return $this->reload($id, $scope);
        });

        $this->notifyAssignee($booking, $actor, 'Tour booking assigned');

        return $booking;
    }

    /**
     * Edit the trip details of an open booking. A blank amount is re-priced from the package.
     *
     * @param array<string,mixed> $trip from TourBookingValidator::trip()
     */
    public function update(TourBooking $booking, array $trip, User $actor, int $expectedVersion): TourBooking
    {
        $this->authorize('edit', $booking, $actor, 'tours.bookings.edit');
        if (!$booking->isOpen()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, "A {$booking->status} booking can no longer be edited.", []);
        }

        $package = $this->resolvePackage($trip['package'], $booking->packageId);
        $assignee = $trip['assigned_to'] ?? $booking->assignedTo;
        if ($assignee !== null && $assignee !== $booking->assignedTo) {
            $this->assertAssignee($assignee, $booking->branchId);
        }
        [$amount, $currency] = $this->resolveAmount($trip, $package);

        if ($booking->status === 'confirmed') {
            $this->assertConfirmable($amount, $trip['travel_date'], $booking->travelDate);
        }

        $scope = $this->scopes->resolve($actor);
        $changes = [
            'tour_package_id' => $package?->id, 'travel_date' => $trip['travel_date'], 'return_date' => $trip['return_date'],
            'adults' => $trip['adults'], 'children' => $trip['children'], 'total_amount' => $amount, 'currency' => $currency,
            'assigned_to' => $assignee, 'notes' => $trip['notes'],
        ];

        $updated = $this->db->transaction(function () use ($booking, $changes, $expectedVersion, $scope, $actor): TourBooking {
            if ($this->bookings->updateVersioned($booking->id, $changes, $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('tour_booking', $booking->publicId);
            }
            $fresh = $this->reload($booking->id, $scope);
            $this->audit->log('updated', 'tours', 'tour_booking', $booking->id, $this->snapshot($booking), $this->snapshot($fresh), null, $actor);

            return $fresh;
        });

        if ($updated->assignedTo !== $booking->assignedTo) {
            $this->notifyAssignee($updated, $actor, 'Tour booking assigned');
        }

        return $updated;
    }

    public function changeStatus(TourBooking $booking, string $to, User $actor, int $expectedVersion, ?string $reason = null): TourBooking
    {
        $this->authorize($to === 'cancelled' ? 'cancel' : 'changeStatus', $booking, $actor, $to === 'cancelled' ? 'tours.bookings.delete' : 'tours.bookings.change_status');
        $this->statuses->assert('tour_booking', $booking->status, $to);

        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;
        if ($to === 'cancelled' && $reason === null) {
            throw new ValidationException(['reason' => ['Please give a reason for the cancellation.']]);
        }

        match ($to) {
            'quoted'     => $this->assertQuotable($booking->totalAmount),
            'confirmed'  => $this->assertConfirmable($booking->totalAmount, $booking->travelDate, null),
            'travelling' => $this->assertCanStart($booking->travelDate),
            default      => null,
        };

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($booking, $to, $reason, $expectedVersion, $scope, $actor): TourBooking {
            if ($this->bookings->updateVersioned($booking->id, ['status' => $to], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('tour_booking', $booking->publicId);
            }
            $this->history->append($booking->id, $booking->status, $to, $reason !== null ? mb_substr($reason, 0, 255) : null, $actor->id);
            $this->audit->log('status_changed', 'tours', 'tour_booking', $booking->id, ['status' => $booking->status], ['status' => $to], $reason, $actor);

            return $this->reload($booking->id, $scope);
        });
    }

    // ---- business gates ----------------------------------------------

    private function assertQuotable(string $amount): void
    {
        if ((float) $amount <= 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Set the price before quoting this booking.', []);
        }
    }

    /**
     * A confirmed trip needs a price and a travel date that is not in the past. When the date is
     * being kept as it was, an already-confirmed booking is not re-checked against today.
     */
    private function assertConfirmable(string $amount, ?string $travelDate, ?string $unchangedFrom): void
    {
        if ((float) $amount <= 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A confirmed booking needs a price.', []);
        }
        if ($travelDate === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A confirmed booking needs a travel date.', []);
        }
        if ($travelDate !== $unchangedFrom && $travelDate < gmdate('Y-m-d')) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The travel date is in the past.', []);
        }
    }

    private function assertCanStart(?string $travelDate): void
    {
        if ($travelDate === null || $travelDate > gmdate('Y-m-d', time() + self::TZ_SLACK_HOURS * 3600)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The trip cannot start before its travel date.', []);
        }
    }

    // ---- internals -----------------------------------------------------

    /** The package a booking points at: it must exist and be active, unless the booking already had it. */
    private function resolvePackage(?string $publicId, ?int $currentId): ?TourPackage
    {
        if ($publicId === null) {
            return null;
        }
        $package = $this->packages->findByPublicId($publicId);
        if ($package === null) {
            throw new ValidationException(['package' => ['That package no longer exists.']]);
        }
        if ($package->status !== 'active' && $package->id !== $currentId) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only an active package can be booked.', []);
        }

        return $package;
    }

    /**
     * @param array<string,mixed> $trip
     * @return array{0:string,1:string} amount (2 dp) and currency
     */
    private function resolveAmount(array $trip, ?TourPackage $package): array
    {
        if ($trip['total_amount'] !== null) {
            return [(string) $trip['total_amount'], (string) ($trip['currency'] ?? $package?->currency ?? self::DEFAULT_CURRENCY)];
        }
        if ($package?->price !== null) {
            $travellers = (int) $trip['adults'] + (int) $trip['children'];

            return [number_format((float) $package->price * $travellers, 2, '.', ''), (string) ($package->currency ?? self::DEFAULT_CURRENCY)];
        }

        return ['0.00', (string) ($trip['currency'] ?? self::DEFAULT_CURRENCY)];
    }

    private function assertAssignee(?int $userId, int $branchId): void
    {
        if ($userId !== null && !$this->users->canServeBranch($userId, $branchId)) {
            throw new ValidationException(['assigned_to' => ['That person cannot be assigned bookings in this branch.']]);
        }
    }

    private function authorize(string $ability, TourBooking $booking, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $booking)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function notifyAssignee(TourBooking $booking, User $actor, string $title): void
    {
        if ($booking->assignedTo === null || $booking->assignedTo === $actor->id) {
            return;
        }
        $this->notifications->notify(
            userId: $booking->assignedTo,
            type: 'tour_booking_assigned',
            title: "{$title}: {$booking->customerName}",
            body: "{$booking->bookingNumber} · {$booking->tripLabel()}" . ($booking->travelDate !== null ? " · {$booking->travelDate}" : ''),
            linkType: 'tour_booking',
            linkId: $booking->id,
            linkFragment: null,
        );
    }

    private function reload(int $id, BranchScope $scope): TourBooking
    {
        $booking = $this->bookings->findById($id, $scope);
        if ($booking === null) {
            throw new \RuntimeException('Tour booking vanished mid-operation.');
        }

        return $booking;
    }

    /** @return array<string,mixed> */
    private function snapshot(TourBooking $b): array
    {
        return [
            'package_id' => $b->packageId, 'travel_date' => $b->travelDate, 'return_date' => $b->returnDate,
            'adults' => $b->adults, 'children' => $b->children, 'total_amount' => $b->totalAmount,
            'currency' => $b->currency, 'assigned_to' => $b->assignedTo, 'status' => $b->status,
        ];
    }
}
