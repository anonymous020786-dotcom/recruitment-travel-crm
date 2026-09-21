<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Candidate;
use App\Repositories\CandidateEducationRepository;
use App\Repositories\CandidateExperienceRepository;
use App\Repositories\CandidateRepository;
use App\Services\CandidateService;
use App\Support\Db;
use App\Support\ListQuery;
use App\Validators\CandidateEducationValidator;
use App\Validators\CandidateExperienceValidator;
use App\Validators\CandidateValidator;

/**
 * Candidate screens. Candidates are created only via lead conversion (see
 * LeadService::convert()); this controller covers viewing and editing the
 * profile. Education, experience, skills, preferences, passport and
 * documents tabs land in follow-up steps.
 */
final class CandidateController extends CrmController
{
    public function __construct(
        private readonly CandidateRepository $candidates,
        private readonly CandidateService $service,
        private readonly CandidateEducationRepository $education,
        private readonly CandidateExperienceRepository $experience,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, CandidateRepository::SORT, CandidateRepository::FILTER_KEYS, 'created_at');
        $page = $this->candidates->paginate($query, $this->scope());

        return view_response('crm.candidates.index', [
            'page'   => $page,
            'query'  => $query,
            'stages' => $this->candidates->stageOptions(),
        ]);
    }

    public function show(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);
        authorize('view', $model);

        return view_response('crm.candidates.show', [
            'candidate'   => $model,
            'counselors'  => $this->candidates->assignableCounselors($this->scope()),
            'canEdit'     => can('update', $model),
            'education'   => $this->education->forCandidate($model->id),
            'experience'  => $this->experience->forCandidate($model->id),
            'canEducation' => can('manageEducation', $model),
            'canExperience' => can('manageExperience', $model),
            'countries'   => $this->countryOptions(),
        ]);
    }

    public function edit(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);
        authorize('update', $model);

        return view_response('crm.candidates.edit', [
            'candidate' => $model,
            'countries' => $this->countryOptions(),
        ]);
    }

    public function update(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);
        authorize('update', $model);

        try {
            $data = (new CandidateValidator())->validate($request->only($this->fieldKeys()));
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/candidates/' . $model->publicId . '/edit');
        }

        try {
            $this->service->updateProfile(
                $model,
                $data,
                $data,
                $this->currentUser(),
                (int) $request->input('record_version', $model->recordVersion),
            );
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/candidates/' . $model->publicId . '/edit');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This candidate changed just now. Please try again.');

            return Response::redirect('/candidates/' . $model->publicId . '/edit');
        }

        flash('status', 'Candidate profile updated.');

        return Response::redirect('/candidates/' . $model->publicId);
    }

    public function reassignCounselor(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);
        $counselor = $request->input('assigned_counselor');
        $counselorId = ($counselor === null || $counselor === '' || $counselor === '0') ? null : (int) $counselor;

        try {
            $this->service->reassignCounselor(
                $model,
                $counselorId,
                $this->currentUser(),
                (int) $request->input('record_version', $model->recordVersion),
            );
            flash('status', $counselorId === null ? 'Counselor unassigned.' : 'Counselor reassigned.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not reassign the counselor.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This candidate changed just now. Please try again.');
        }

        return Response::redirect('/candidates/' . $model->publicId);
    }

    public function storeEducation(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new CandidateEducationValidator())->validate($request->only($this->educationFieldKeys()));
            $this->service->addEducation($model, $data, $this->currentUser());
            flash('status', 'Education added.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add that education record.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#education');
    }

    public function updateEducation(Request $request, string $candidate, string $education): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new CandidateEducationValidator())->validate($request->only($this->educationFieldKeys()));
            $this->service->updateEducation($model, (int) $education, $data, $this->currentUser());
            flash('status', 'Education updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not update that education record.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#education');
    }

    public function destroyEducation(string $candidate, string $education): Response
    {
        $model = $this->find($candidate);

        try {
            $this->service->removeEducation($model, (int) $education, $this->currentUser());
            flash('status', 'Education removed.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#education');
    }

    public function storeExperience(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new CandidateExperienceValidator())->validate($request->only($this->experienceFieldKeys()));
            $this->service->addExperience($model, $data, $this->currentUser());
            flash('status', 'Experience added.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add that experience record.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#experience');
    }

    public function updateExperience(Request $request, string $candidate, string $experience): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new CandidateExperienceValidator())->validate($request->only($this->experienceFieldKeys()));
            $this->service->updateExperience($model, (int) $experience, $data, $this->currentUser());
            flash('status', 'Experience updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not update that experience record.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#experience');
    }

    public function destroyExperience(string $candidate, string $experience): Response
    {
        $model = $this->find($candidate);

        try {
            $this->service->removeExperience($model, (int) $experience, $this->currentUser());
            flash('status', 'Experience removed.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#experience');
    }

    // ---- internals -------------------------------------------------

    private function find(string $publicId): Candidate
    {
        $model = $this->candidates->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Candidate not found.');
        }

        return $model;
    }

    /** @return list<string> */
    private function fieldKeys(): array
    {
        return [
            'full_name', 'gender', 'date_of_birth', 'primary_phone', 'alternate_phone', 'email',
            'nationality', 'city', 'state', 'country',
            'marital_status', 'current_country', 'highest_qualification', 'total_experience_years',
        ];
    }

    /** @return list<string> */
    private function educationFieldKeys(): array
    {
        return ['level', 'institution', 'board_university', 'field_of_study', 'start_year', 'end_year', 'grade'];
    }

    /** @return list<string> */
    private function experienceFieldKeys(): array
    {
        return ['employer_name', 'job_title', 'country', 'start_date', 'end_date', 'is_current', 'responsibilities'];
    }

    /** @return array<string,string> */
    private function countryOptions(): array
    {
        $rows = app(Db::class)->select(
            'SELECT code, name FROM countries WHERE is_active = 1 ORDER BY is_gcc DESC, name',
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['code']] = (string) $r['name'];
        }

        return $out;
    }
}
