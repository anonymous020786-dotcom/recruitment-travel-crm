<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Candidate;
use App\Repositories\ActivityLogRepository;
use App\Repositories\CandidateEducationRepository;
use App\Repositories\CandidateExperienceRepository;
use App\Repositories\CandidateDocumentRepository;
use App\Repositories\CandidatePreferencesRepository;
use App\Repositories\CandidateRepository;
use App\Repositories\CandidateSkillRepository;
use App\Repositories\ChecklistRepository;
use App\Repositories\DocumentTypeRepository;
use App\Repositories\PassportRepository;
use App\Repositories\TaskRepository;
use App\Services\CandidateService;
use App\Support\Db;
use App\Support\ListQuery;
use App\Validators\CandidateEducationValidator;
use App\Validators\CandidateExperienceValidator;
use App\Validators\CandidatePreferencesValidator;
use App\Validators\CandidateSkillValidator;
use App\Validators\CandidateValidator;
use App\Validators\PassportValidator;
use App\Validators\TaskValidator;

/**
 * Candidate screens. Candidates are created only via lead conversion (see
 * LeadService::convert()); this controller covers viewing and editing the
 * 360° profile (identity, education, experience, skills, preferences,
 * passports, documents, timeline, tasks). Job-application tabs land in later
 * phases.
 */
final class CandidateController extends CrmController
{
    public function __construct(
        private readonly CandidateRepository $candidates,
        private readonly CandidateService $service,
        private readonly CandidateEducationRepository $education,
        private readonly CandidateExperienceRepository $experience,
        private readonly CandidateSkillRepository $skills,
        private readonly CandidatePreferencesRepository $preferences,
        private readonly PassportRepository $passports,
        private readonly TaskRepository $tasks,
        private readonly ActivityLogRepository $activity,
        private readonly CandidateDocumentRepository $documents,
        private readonly DocumentTypeRepository $documentTypes,
        private readonly ChecklistRepository $checklist,
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
            'skills'      => $this->skills->forCandidate($model->id),
            'canSkills'   => can('manageSkills', $model),
            'preferences' => $this->preferences->find($model->id),
            'canPreferences' => can('managePreferences', $model),
            'passports'   => $this->passports->forCandidate($model->id),
            'canPassport' => can('managePassport', $model),
            'timeline'    => $this->buildTimeline($this->candidates->notes($model->id), $this->activity->forRecord('candidate', $model->id, 100)),
            'canAddNote'  => can('addNote', $model),
            'tasks'       => $this->tasks->forRelated('candidate', $model->id),
            'canTasks'    => can('manageTasks', $model),
            'taskAssignees' => $this->candidates->assignableCounselors($this->scope()),
            'documents'   => $this->documents->forCandidate($model->id),
            'documentTypes' => $this->documentTypes->active(),
            'canUploadDocument' => can('uploadDocument', $model),
            'canDeleteDocument' => can('documents.delete'),
            'canVerifyDocument' => can('documents.verify'),
            'canRejectDocument' => can('documents.reject'),
            'checklist'   => $this->checklist->forCandidate($model->id),
            'canManageChecklist' => can('documents.checklist.manage'),
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

    public function storeSkill(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new CandidateSkillValidator())->validate($request->only(['skill_name', 'category', 'proficiency', 'years']));
            $this->service->addSkill($model, $data, $this->currentUser());
            flash('status', 'Skill saved.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not save that skill.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#skills');
    }

    public function destroySkill(string $candidate, string $skill): Response
    {
        $model = $this->find($candidate);

        try {
            $this->service->removeSkill($model, (int) $skill, $this->currentUser());
            flash('status', 'Skill removed.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#skills');
    }

    public function savePreferences(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new CandidatePreferencesValidator())->validate($request->only([
                'preferred_countries', 'preferred_job_titles', 'min_expected_salary', 'salary_currency',
                'willing_to_relocate', 'available_from', 'passport_ready', 'notes',
            ]));
            $this->service->savePreferences($model, $data, $this->currentUser());
            flash('status', 'Preferences saved.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not save preferences.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#preferences');
    }

    public function storePassport(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new PassportValidator())->validate($request->only($this->passportFieldKeys()));
            $this->service->addPassport($model, $data, $this->currentUser());
            flash('status', 'Passport added.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add that passport.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#passports');
    }

    public function updatePassport(Request $request, string $candidate, string $passport): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new PassportValidator())->validate($request->only($this->passportFieldKeys()));
            $this->service->updatePassport($model, (int) $passport, $data, $this->currentUser());
            flash('status', 'Passport updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not update that passport.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#passports');
    }

    public function destroyPassport(string $candidate, string $passport): Response
    {
        $model = $this->find($candidate);

        try {
            $this->service->removePassport($model, (int) $passport, $this->currentUser());
            flash('status', 'Passport removed.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#passports');
    }

    public function addNote(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);

        try {
            $this->service->addNote($model, (string) $request->input('body', ''), $this->currentUser());
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add note.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#timeline');
    }

    public function storeTask(Request $request, string $candidate): Response
    {
        $model = $this->find($candidate);

        try {
            $data = (new TaskValidator())->validate($request->only(['title', 'description', 'priority', 'due_date', 'due_time', 'assigned_to']));
            $this->service->addTask($model, $data, $this->currentUser());
            flash('status', 'Task added.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add that task.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#tasks');
    }

    public function completeTask(string $candidate, string $task): Response
    {
        $model = $this->find($candidate);

        try {
            $this->service->completeTask($model, (int) $task, $this->currentUser());
            flash('status', 'Task completed.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#tasks');
    }

    public function cancelTask(string $candidate, string $task): Response
    {
        $model = $this->find($candidate);

        try {
            $this->service->cancelTask($model, (int) $task, $this->currentUser());
            flash('status', 'Task cancelled.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#tasks');
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

    /** @return list<string> */
    private function passportFieldKeys(): array
    {
        return ['passport_number', 'issue_date', 'expiry_date', 'place_of_issue', 'nationality', 'is_primary', 'held_by'];
    }

    /** @param list<array<string,mixed>> $notes @param list<array<string,mixed>> $logs @return list<array{type:string,at:string,actor:?string,text:string}> */
    private function buildTimeline(array $notes, array $logs): array
    {
        $items = [];

        foreach ($notes as $n) {
            $items[] = [
                'type' => 'note',
                'at' => (string) $n['created_at'],
                'actor' => $n['user_name'] ?? null,
                'text' => (string) $n['body'],
            ];
        }

        $labels = [
            'created' => 'created the candidate',
            'updated' => 'updated the profile',
            'counselor_assigned' => 'changed the counselor',
            'note_added' => 'added a note',
            'education_added' => 'added an education record',
            'education_updated' => 'updated an education record',
            'education_removed' => 'removed an education record',
            'experience_added' => 'added an experience record',
            'experience_updated' => 'updated an experience record',
            'experience_removed' => 'removed an experience record',
            'skill_added' => 'added or updated a skill',
            'skill_removed' => 'removed a skill',
            'preferences_saved' => 'saved preferences',
            'passport_added' => 'added a passport',
            'passport_updated' => 'updated a passport',
            'passport_removed' => 'removed a passport',
            'task_added' => 'added a task',
            'task_completed' => 'completed a task',
            'task_cancelled' => 'cancelled a task',
            'document_uploaded' => 'uploaded a document',
            'document_deleted' => 'removed a document',
            'document_review_started' => 'started reviewing a document',
            'document_verified' => 'verified a document',
            'document_rejected' => 'rejected a document',
            'checklist_updated' => 'updated the document checklist',
        ];

        foreach ($logs as $l) {
            $action = (string) $l['action'];
            if ($action === 'note_added') {
                continue; // already shown as the note itself
            }
            $items[] = [
                'type' => 'event',
                'at' => (string) $l['created_at'],
                'actor' => null,
                'text' => $labels[$action] ?? $action,
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $items;
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
