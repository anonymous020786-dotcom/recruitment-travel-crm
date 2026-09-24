<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Application;
use App\Models\FlightBooking;
use App\Models\Placement;
use App\Repositories\ApplicationRepository;
use App\Repositories\CandidateRepository;
use App\Repositories\EmployerRepository;
use App\Repositories\FlightRepository;
use App\Repositories\PlacementRepository;
use App\Repositories\TravelRepository;
use App\Services\AttachmentService;
use App\Services\TravelService;
use App\Support\ListQuery;
use App\Validators\TravelValidator;

/**
 * Travel desk: the pipeline of approved candidates through ticketing and departure,
 * the placements register, and the actions posted from an application's travel card.
 */
final class TravelController extends CrmController
{
    private const FLIGHT_FIELDS = [
        'status', 'pnr', 'airline', 'flight_number', 'departure_airport', 'arrival_airport',
        'departure_at', 'arrival_at', 'baggage_allowance', 'ticket_price', 'currency', 'notes',
    ];

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly TravelRepository $pipeline,
        private readonly PlacementRepository $placements,
        private readonly FlightRepository $flights,
        private readonly ApplicationRepository $applications,
        private readonly CandidateRepository $candidates,
        private readonly EmployerRepository $employers,
        private readonly TravelService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, TravelRepository::SORT, TravelRepository::FILTER_KEYS, 'updated');

        return view_response('crm.travel.index', [
            'page'   => $this->pipeline->paginate($query, $this->scope()),
            'query'  => $query,
            'counts' => $this->pipeline->stageCounts($this->scope()),
        ]);
    }

    public function placements(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, PlacementRepository::SORT, PlacementRepository::FILTER_KEYS, 'placed_on');

        return view_response('crm.travel.placements', [
            'page'      => $this->placements->paginate($query, $this->scope()),
            'query'     => $query,
            'employers' => $this->employers->options($this->scope()),
        ]);
    }

    public function bookFlight(Request $request, string $application): Response
    {
        return $this->onApplication($application, 'Flight added.', function (Application $app) use ($request): void {
            $this->service->bookFlight($app, (new TravelValidator())->flight($request->only(self::FLIGHT_FIELDS)), $this->currentUser());
        });
    }

    public function updateFlight(Request $request, string $flight): Response
    {
        return $this->onFlight($flight, 'Flight updated.', function (FlightBooking $f) use ($request): void {
            $this->service->updateFlight($f, (new TravelValidator())->flight($request->only(self::FLIGHT_FIELDS)), $this->currentUser());
        });
    }

    public function attachTicket(Request $request, string $flight): Response
    {
        return $this->onFlight($flight, 'Ticket attached to the flight and to the candidate\'s documents.', function (FlightBooking $f) use ($request): void {
            $this->attachments->flightTicket($f, $request->file('file') ?? [], $this->currentUser());
        });
    }

    public function flightStatus(Request $request, string $flight): Response
    {
        return $this->onFlight($flight, 'Flight status updated.', function (FlightBooking $f) use ($request): void {
            $this->service->changeFlightStatus($f, (string) $request->input('status', ''), $this->currentUser());
        });
    }

    public function departure(Request $request, string $application): Response
    {
        return $this->onApplication($application, 'Departure recorded.', function (Application $app) use ($request): void {
            $this->service->recordDeparture($app, (new TravelValidator())->movement($request->only(['departed_at', 'notes']), 'departed_at'), $this->currentUser());
        });
    }

    public function arrival(Request $request, string $application): Response
    {
        return $this->onApplication($application, 'Arrival confirmed.', function (Application $app) use ($request): void {
            $this->service->confirmArrival($app, (new TravelValidator())->movement($request->only(['arrived_at', 'notes']), 'arrived_at'), $this->currentUser());
        });
    }

    public function place(Request $request, string $application): Response
    {
        return $this->onApplication($application, 'Placement recorded.', function (Application $app) use ($request): void {
            $this->service->place($app, (new TravelValidator())->placement($request->only(['placed_on', 'monthly_salary', 'currency', 'contract_end'])), $this->currentUser());
        });
    }

    public function profile(Request $request, string $application): Response
    {
        return $this->onApplication($application, 'Travel profile saved.', function (Application $app) use ($request): void {
            $candidate = $this->candidates->findByPublicId($app->candidatePublicId, $this->scope());
            if ($candidate === null) {
                abort(404, 'Candidate not found.');
            }
            $this->service->saveProfile($candidate, $app, (new TravelValidator())->profile($request->only(['preferred_departure_city', 'notes'])), $this->currentUser());
        });
    }

    public function placementStatus(Request $request, string $placement): Response
    {
        $model = $this->placements->findByPublicId($placement, $this->scope());
        if ($model === null) {
            abort(404, 'Placement not found.');
        }

        return $this->run('/applications/' . $model->applicationPublicId . '#travel', 'Placement updated.', function () use ($request, $model): void {
            $this->service->endPlacement($model, (string) $request->input('status', ''), $request->input('reason'), $this->currentUser());
        });
    }

    // ---- helpers ---------------------------------------------------

    private function onApplication(string $publicId, string $success, callable $do): Response
    {
        $app = $this->applications->findByPublicId($publicId, $this->scope());
        if ($app === null) {
            abort(404, 'Application not found.');
        }

        return $this->run('/applications/' . $app->publicId . '#travel', $success, static fn () => $do($app));
    }

    private function onFlight(string $publicId, string $success, callable $do): Response
    {
        $flight = $this->flights->findByPublicId($publicId, $this->scope());
        if ($flight === null) {
            abort(404, 'Flight not found.');
        }
        $back = $flight->applicationPublicId !== null ? '/applications/' . $flight->applicationPublicId . '#travel' : '/travel';

        return $this->run($back, $success, static fn () => $do($flight));
    }

    /** Run an action, flash the outcome, and go back to where the user was. */
    private function run(string $back, string $success, callable $do): Response
    {
        try {
            $do();
            flash('status', $success);
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the details.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to do that.');
        }

        return Response::redirect($back);
    }
}
