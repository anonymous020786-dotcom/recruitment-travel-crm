<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Interview;
use App\Repositories\ApplicationRepository;
use App\Repositories\InterviewRepository;
use App\Services\InterviewService;
use App\Support\ListQuery;
use App\Validators\InterviewValidator;

/** Interview screens: the schedule board, and the schedule / confirm / reschedule / outcome actions posted from an application. */
final class InterviewController extends CrmController
{
    private const FIELDS = ['type', 'scheduled_date', 'scheduled_time', 'location', 'meeting_link', 'interviewer', 'notes'];

    public function __construct(
        private readonly InterviewRepository $interviews,
        private readonly ApplicationRepository $applications,
        private readonly InterviewService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, InterviewRepository::SORT, InterviewRepository::FILTER_KEYS, 'when');

        return view_response('crm.interviews.index', [
            'page'  => $this->interviews->paginate($query, $this->scope()),
            'query' => $query,
        ]);
    }

    public function store(Request $request, string $application): Response
    {
        $app = $this->applications->findByPublicId($application, $this->scope());
        if ($app === null) {
            abort(404, 'Application not found.');
        }

        try {
            $interview = $this->service->schedule($app, (new InterviewValidator())->schedule($request->only(self::FIELDS)), $this->currentUser());
            flash('status', "Round {$interview->roundNo} interview scheduled for {$interview->whenLabel()}.");
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the interview details.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to schedule interviews.');
        }

        return Response::redirect('/applications/' . $app->publicId . '#interviews');
    }

    public function confirm(string $interview): Response
    {
        $model = $this->find($interview);

        try {
            $this->service->confirm($model, $this->currentUser());
            flash('status', 'Interview confirmed.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to edit interviews.');
        }

        return $this->back($model);
    }

    public function reschedule(Request $request, string $interview): Response
    {
        $model = $this->find($interview);

        try {
            $new = $this->service->reschedule(
                $model,
                (new InterviewValidator())->schedule($request->only(self::FIELDS)),
                (string) $request->input('reason', ''),
                $this->currentUser(),
            );
            flash('status', "Interview rescheduled to {$new->whenLabel()}.");
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the interview details.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to edit interviews.');
        }

        return $this->back($model);
    }

    public function outcome(Request $request, string $interview): Response
    {
        $model = $this->find($interview);

        try {
            $clean = (new InterviewValidator())->outcome($request->only(['outcome', 'feedback']));
            $this->service->recordOutcome($model, $clean['outcome'], $clean['feedback'], $this->currentUser());
            flash('status', 'Interview outcome recorded.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the outcome details.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to record interview outcomes.');
        }

        return $this->back($model);
    }

    private function back(Interview $model): Response
    {
        return Response::redirect('/applications/' . $model->applicationPublicId . '#interviews');
    }

    private function find(string $publicId): Interview
    {
        $model = $this->interviews->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Interview not found.');
        }

        return $model;
    }
}
