<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Domain\StatusMachine;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\TourBooking;
use App\Repositories\LeadRepository;
use App\Repositories\TourBookingHistoryRepository;
use App\Repositories\TourBookingRepository;
use App\Repositories\TourPackageRepository;
use App\Services\TourBookingService;
use App\Support\Db;
use App\Support\ListQuery;
use App\Validators\TourBookingValidator;

/** Tour booking screens: the register, inquiry/booking form, booking page with history, and status moves. */
final class TourBookingController extends CrmController
{
    private const CUSTOMER_FIELDS = ['customer_name', 'customer_phone', 'customer_email'];
    private const TRIP_FIELDS = ['package', 'travel_date', 'return_date', 'adults', 'children', 'total_amount', 'currency', 'assigned_to', 'notes'];

    public function __construct(
        private readonly TourBookingRepository $bookings,
        private readonly TourBookingHistoryRepository $history,
        private readonly TourPackageRepository $packages,
        private readonly LeadRepository $leads,
        private readonly TourBookingService $service,
        private readonly StatusMachine $statuses,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, TourBookingRepository::SORT, TourBookingRepository::FILTER_KEYS, 'created_at');

        return view_response('crm.tours.bookings.index', [
            'page'      => $this->bookings->paginate($query, $this->scope()),
            'query'     => $query,
            'counts'    => $this->bookings->statusCounts($this->scope()),
            'packages'  => $this->packages->activeOptions(),
            'canCreate' => can('tours.bookings.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        return view_response('crm.tours.bookings.create', ['selectedPackage' => (string) $request->input('package', '')] + $this->formData());
    }

    public function store(Request $request): Response
    {
        try {
            $validator = new TourBookingValidator();
            $customer = $validator->customer($request->only(self::CUSTOMER_FIELDS));
            $branch = $request->input('branch_id');
            $booking = $this->service->create(
                $customer,
                $validator->trip($request->only(self::TRIP_FIELDS)),
                $this->currentUser(),
                $branch !== null && ctype_digit((string) $branch) ? (int) $branch : null,
            );
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/tours/bookings/create');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return redirect_with_errors([], $request->all(), '/tours/bookings/create');
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You cannot create tour bookings.');

            return redirect_with_errors([], $request->all(), '/tours/bookings/create');
        }

        // The customer is matched by phone / email, so the name on file can differ from what was typed.
        $typed = mb_strtolower((string) $customer['full_name']);
        flash('status', "Booking {$booking->bookingNumber} created."
            . (mb_strtolower($booking->customerName) !== $typed ? " Matched the existing customer “{$booking->customerName}” by phone or email." : ''));

        return Response::redirect('/tours/bookings/' . $booking->publicId);
    }

    public function show(string $booking): Response
    {
        $model = $this->find($booking);
        authorize('view', $model);

        return view_response('crm.tours.bookings.show', [
            'booking'      => $model,
            'history'      => $this->history->forBooking($model->id),
            'canEdit'      => can('edit', $model) && $model->isOpen(),
            'canStatus'    => can('changeStatus', $model),
            'canCancel'    => can('cancel', $model),
            'nextStatuses' => $this->statuses->transitionsFrom('tour_booking', $model->status),
        ]);
    }

    public function edit(string $booking): Response
    {
        $model = $this->find($booking);
        authorize('edit', $model);
        if (!$model->isOpen()) {
            session()?->flash('error_toast', "A {$model->status} booking can no longer be edited.");

            return Response::redirect('/tours/bookings/' . $model->publicId);
        }

        return view_response('crm.tours.bookings.edit', ['booking' => $model] + $this->formData($model));
    }

    public function update(Request $request, string $booking): Response
    {
        $model = $this->find($booking);
        authorize('edit', $model);

        try {
            $this->service->update(
                $model,
                (new TourBookingValidator())->trip($request->only(self::TRIP_FIELDS)),
                $this->currentUser(),
                (int) $request->input('record_version', $model->recordVersion),
            );
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/tours/bookings/' . $model->publicId . '/edit');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This booking changed just now. Please review it and try again.');

            return Response::redirect('/tours/bookings/' . $model->publicId);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return redirect_with_errors([], $request->all(), '/tours/bookings/' . $model->publicId . '/edit');
        }

        flash('status', 'Booking updated.');

        return Response::redirect('/tours/bookings/' . $model->publicId);
    }

    public function changeStatus(Request $request, string $booking): Response
    {
        $model = $this->find($booking);

        try {
            $this->service->changeStatus(
                $model,
                (string) $request->input('status', ''),
                $this->currentUser(),
                (int) $request->input('record_version', $model->recordVersion),
                $request->input('reason'),
            );
            flash('status', 'Booking status updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not change the status.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This booking changed just now. Please review it and try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to make that status change.');
        }

        return Response::redirect('/tours/bookings/' . $model->publicId);
    }

    // ---- internals -------------------------------------------------

    /** @return array<string,mixed> */
    private function formData(?TourBooking $booking = null): array
    {
        $packages = $this->packages->activeOptions();
        // A booking keeps its package even after the package is archived.
        if ($booking?->packagePublicId !== null && !isset($packages[$booking->packagePublicId])) {
            $packages[$booking->packagePublicId] = $booking->packageName . ' (not active)';
        }

        $user = $this->currentUser();
        $branches = $this->branchOptions();

        return [
            'packages'  => $packages,
            'assignees' => array_column($this->leads->assignableUsers($this->scope()), 'name', 'id'),
            // A branch picker is only needed when there is a real choice, or no default to fall back on.
            'branches'  => count($branches) > 1 || $user->primaryBranchId === null ? $branches : [],
            'defaultBranch' => $user->primaryBranchId,
        ];
    }

    /** @return array<int,string> id => name of the active branches the viewer may book tours into */
    private function branchOptions(): array
    {
        [$branchSql, $bind] = $this->scope()->whereClause('id');
        $rows = app(Db::class)->select("SELECT id, name FROM branches WHERE is_active = 1 AND {$branchSql} ORDER BY name LIMIT 200", $bind);

        return array_column($rows, 'name', 'id');
    }

    private function find(string $publicId): TourBooking
    {
        $model = $this->bookings->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Tour booking not found.');
        }

        return $model;
    }
}
