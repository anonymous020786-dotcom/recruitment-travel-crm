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

    private function find(string $publicId): Application
    {
        $model = $this->applications->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Application not found.');
        }

        return $model;
    }
}
