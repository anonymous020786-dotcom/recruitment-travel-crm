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
use App\Models\Application;
use App\Repositories\ApplicationHistoryRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\CandidateRepository;
use App\Repositories\EmployerRepository;
use App\Repositories\InterviewRepository;
use App\Repositories\JobRepository;
use App\Services\ApplicationService;
use App\Services\TravelService;
use App\Support\ListQuery;

/** Application screens: pipeline list, profile with history, apply, and status changes. */
final class ApplicationController extends CrmController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApplicationHistoryRepository $history,
        private readonly CandidateRepository $candidates,
        private readonly JobRepository $jobs,
        private readonly EmployerRepository $employers,
        private readonly ApplicationService $service,
        private readonly StatusMachine $statuses,
        private readonly InterviewRepository $interviews,
        private readonly TravelService $travel,
        private readonly \App\Repositories\InvoiceRepository $invoices,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, ApplicationRepository::SORT, ApplicationRepository::FILTER_KEYS, 'applied_at');

        return view_response('crm.applications.index', [
            'page'      => $this->applications->paginate($query, $this->scope()),
            'query'     => $query,
            'statuses'  => $this->statuses->states('application'),
            'employers' => $this->employers->options($this->scope()),
        ]);
    }

    public function store(Request $request): Response
    {
        $candidate = $this->candidates->findByPublicId((string) $request->input('candidate', ''), $this->scope());
        $job = $this->jobs->findByPublicId((string) $request->input('job', ''), $this->scope());
        if ($candidate === null || $job === null) {
            abort(404, 'Candidate or job not found.');
        }

        try {
            $app = $this->service->create($candidate, $job, $this->currentUser());
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/jobs/' . $job->publicId);
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You cannot create applications for that candidate and job.');

            return Response::redirect('/jobs/' . $job->publicId);
        }

        flash('status', "Application {$app->applicationNumber} created.");

        return Response::redirect('/applications/' . $app->publicId);
    }

    public function show(string $application): Response
    {
        $model = $this->find($application);
        authorize('view', $model);

        $canStatus = can('changeStatus', $model);
        $interviews = can('interviews.view') ? $this->interviews->forApplication($model->id, $this->scope()) : [];
        $hasOpen = false;
        foreach ($interviews as $i) {
            $hasOpen = $hasOpen || $i->isOpen();
        }

        return view_response('crm.applications.show', [
            'app'          => $model,
            'history'      => $this->history->forApplication($model->id),
            'canStatus'    => $canStatus,
            'interviews'   => $interviews,
            'canSchedule'  => can('interviews.create') && !$hasOpen && $this->statuses->canTransition('application', $model->status, 'interview_scheduled'),
            'travel'       => $this->travelPanel($model),
            'invoices'     => can('invoices.view') ? $this->invoices->forInvoiceable('application', $model->id, $this->scope()) : null,
            'canInvoice'   => can('invoices.create') && !in_array($model->status, ['rejected', 'cancelled'], true),
            'canOverride'  => can('overrideStatus', $model),
            'nextStatuses' => $this->statuses->transitionsFrom('application', $model->status),
            'allStatuses'  => array_values(array_diff($this->statuses->states('application'), [$model->status])),
        ]);
    }

    public function changeStatus(Request $request, string $application): Response
    {
        $model = $this->find($application);

        try {
            $this->service->changeStatus(
                $model,
                (string) $request->input('status', ''),
                $this->currentUser(),
                (int) $request->input('record_version', $model->recordVersion),
                $request->input('reason'),
                $request->boolean('override'),
            );
            flash('status', 'Application status updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not change the status.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This application changed just now. Please review and try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException $e) {
            session()?->flash('error_toast', 'You do not have permission to make that status change.');
        }

        return Response::redirect('/applications/' . $model->publicId);
    }

    /**
     * Data for the travel card, or null when it does not apply: the card appears once the visa is approved
     * (or the application already has flights / a placement) and only for users who can view travel.
     *
     * @return array<string,mixed>|null
     */
    private function travelPanel(Application $app): ?array
    {
        if (!can('travel.view')) {
            return null;
        }
        $panel = $this->travel->panel($app);
        $relevant = in_array($app->status, ['visa_approved', 'ticket_pending', 'ticket_booked', 'departed', 'placed'], true)
            || $panel['flights'] !== [] || $panel['placement'] !== null;
        if (!$relevant) {
            return null;
        }

        $moves = [];
        foreach ($panel['flights'] as $f) {
            $moves[$f->publicId] = array_values(array_diff($this->statuses->transitionsFrom('flight', $f->status), ['flown']));
        }

        return $panel + [
            'flightMoves'  => $moves,
            'canTickets'   => can('travel.tickets.manage'),
            'canDeparture' => can('travel.departure.manage'),
            'canPlacement' => can('travel.placement.manage'),
            'canProfile'   => can('travel.profile.manage'),
        ];
    }

    private function find(string $publicId): Application
    {
        $model = $this->applications->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Application not found.');
        }

        return $model;
    }
}
