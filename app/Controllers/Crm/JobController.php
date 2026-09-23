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
use App\Models\Employer;
use App\Models\Job;
use App\Repositories\EmployerRepository;
use App\Repositories\JobBenefitRepository;
use App\Repositories\JobRepository;
use App\Repositories\JobRequirementRepository;
use App\Services\JobService;
use App\Support\Db;
use App\Support\ListQuery;
use App\Validators\JobRequirementValidator;
use App\Validators\JobValidator;

/** Job posting screens: list, create/edit, profile with requirements/benefits, lifecycle and publish actions. */
final class JobController extends CrmController
{
    private const FIELDS = [
        'title', 'country', 'city', 'vacancies', 'salary_min', 'salary_max', 'currency', 'experience_required',
        'qualification', 'age_min', 'age_max', 'gender_requirement', 'accommodation', 'food', 'transport',
        'working_hours', 'overtime', 'contract_duration_months', 'interview_type', 'deadline', 'description',
    ];

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobRequirementRepository $requirements,
        private readonly JobBenefitRepository $benefits,
        private readonly EmployerRepository $employers,
        private readonly JobService $service,
        private readonly StatusMachine $statuses,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, JobRepository::SORT, JobRepository::FILTER_KEYS, 'created_at');

        return view_response('crm.jobs.index', [
            'page'      => $this->jobs->paginate($query, $this->scope()),
            'query'     => $query,
            'countries' => $this->countryOptions(),
            'employers' => $this->employers->options($this->scope()),
            'canCreate' => can('jobs.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        return view_response('crm.jobs.create', [
            'countries' => $this->countryOptions(),
            'employers' => $this->employers->options($this->scope()),
            'selectedEmployer' => (string) $request->input('employer', ''),
        ]);
    }

    public function store(Request $request): Response
    {
        $employer = $this->employers->findByPublicId((string) $request->input('employer', ''), $this->scope());
        if ($employer === null) {
            return redirect_with_errors(['employer' => ['Choose an employer.']], $request->all(), '/jobs/create');
        }

        try {
            $job = $this->service->create($employer, (new JobValidator())->validate($request->only(self::FIELDS)), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/jobs/create');
        } catch (DomainRuleException|AuthorizationException $e) {
            session()?->flash('error_toast', $e instanceof DomainRuleException ? $e->getMessage() : 'You cannot post jobs for that employer.');

            return redirect_with_errors([], $request->all(), '/jobs/create');
        }

        flash('status', "Job {$job->jobNumber} created as a draft.");

        return Response::redirect('/jobs/' . $job->publicId);
    }

    public function show(string $job): Response
    {
        $model = $this->find($job);
        authorize('view', $model);

        return view_response('crm.jobs.show', [
            'job'          => $model,
            'requirements' => $this->requirements->forJob($model->id),
            'benefits'     => $this->benefits->forJob($model->id),
            'countries'    => $this->countryOptions(),
            'canEdit'      => can('update', $model),
            'canDelete'    => can('delete', $model),
            'canStatus'    => can('changeStatus', $model),
            'canPublish'   => can('publish', $model),
            'nextStatuses' => $this->statuses->transitionsFrom('job', $model->status),
        ]);
    }

    public function edit(string $job): Response
    {
        $model = $this->find($job);
        authorize('update', $model);

        return view_response('crm.jobs.edit', ['job' => $model, 'countries' => $this->countryOptions()]);
    }

    public function update(Request $request, string $job): Response
    {
        $model = $this->find($job);
        authorize('update', $model);

        try {
            $this->service->update($model, (new JobValidator())->validate($request->only(self::FIELDS)), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/jobs/' . $model->publicId . '/edit');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/jobs/' . $model->publicId);
        }

        flash('status', 'Job updated.');

        return Response::redirect('/jobs/' . $model->publicId);
    }

    public function changeStatus(Request $request, string $job): Response
    {
        $model = $this->find($job);

        try {
            $this->service->changeStatus($model, (string) $request->input('status', ''), $this->currentUser(), $request->input('reason'));
            flash('status', 'Job status updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not change the status.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This job changed just now. Please try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to change job status.');
        }

        return Response::redirect('/jobs/' . $model->publicId);
    }

    public function publish(Request $request, string $job): Response
    {
        $model = $this->find($job);

        try {
            $this->service->setPublic($model, $request->boolean('public'), $this->currentUser());
            flash('status', $request->boolean('public') ? 'Job published.' : 'Job unpublished.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to publish jobs.');
        }

        return Response::redirect('/jobs/' . $model->publicId);
    }

    public function destroy(string $job): Response
    {
        $model = $this->find($job);

        try {
            $this->service->delete($model, $this->currentUser());
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/jobs/' . $model->publicId);
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to delete jobs.');

            return Response::redirect('/jobs/' . $model->publicId);
        }

        flash('status', 'Job deleted.');

        return Response::redirect('/jobs');
    }

    public function storeRequirement(Request $request, string $job): Response
    {
        $model = $this->find($job);

        try {
            $this->service->addRequirement($model, (new JobRequirementValidator())->validate($request->only(['label', 'is_mandatory', 'weight'])), $this->currentUser());
            flash('status', 'Requirement added.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add that requirement.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/jobs/' . $model->publicId . '#requirements');
    }

    public function destroyRequirement(string $job, string $requirement): Response
    {
        $model = $this->find($job);

        try {
            $this->service->removeRequirement($model, (int) $requirement, $this->currentUser());
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/jobs/' . $model->publicId . '#requirements');
    }

    public function storeBenefit(Request $request, string $job): Response
    {
        $model = $this->find($job);

        try {
            $this->service->addBenefit($model, (string) $request->input('label', ''), $this->currentUser());
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add that benefit.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/jobs/' . $model->publicId . '#benefits');
    }

    public function destroyBenefit(string $job, string $benefit): Response
    {
        $model = $this->find($job);

        try {
            $this->service->removeBenefit($model, (int) $benefit, $this->currentUser());
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/jobs/' . $model->publicId . '#benefits');
    }

    // ---- internals -------------------------------------------------

    private function find(string $publicId): Job
    {
        $model = $this->jobs->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Job not found.');
        }

        return $model;
    }

    /** @return array<string,string> */
    private function countryOptions(): array
    {
        $rows = app(Db::class)->select('SELECT code, name FROM countries WHERE is_active = 1 ORDER BY is_gcc DESC, name');
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['code']] = (string) $r['name'];
        }

        return $out;
    }
}
