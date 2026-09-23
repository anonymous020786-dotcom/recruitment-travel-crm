<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\MedicalRecord;
use App\Repositories\ApplicationRepository;
use App\Repositories\CandidateRepository;
use App\Repositories\MedicalRepository;
use App\Services\MedicalService;
use App\Support\ListQuery;
use App\Validators\MedicalValidator;

/** Medical screens: the register, plus book / reschedule / attended / result / delete actions posted from a candidate. */
final class MedicalController extends CrmController
{
    public function __construct(
        private readonly MedicalRepository $records,
        private readonly CandidateRepository $candidates,
        private readonly ApplicationRepository $applications,
        private readonly MedicalService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, MedicalRepository::SORT, MedicalRepository::FILTER_KEYS, 'created_at');

        return view_response('crm.medical.index', [
            'page'  => $this->records->paginate($query, $this->scope()),
            'query' => $query,
        ]);
    }

    public function store(Request $request, string $candidate): Response
    {
        $model = $this->candidates->findByPublicId($candidate, $this->scope());
        if ($model === null) {
            abort(404, 'Candidate not found.');
        }

        try {
            $application = null;
            $appId = (string) $request->input('application', '');
            if ($appId !== '') {
                $application = $this->applications->findByPublicId($appId, $this->scope());
                if ($application === null) {
                    throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Choose one of this candidate’s applications.', []);
                }
            }
            $this->service->book($model, $application, (new MedicalValidator())->book($request->only(['medical_center', 'appointment_date', 'notes'])), $this->currentUser());
            flash('status', 'Medical booked.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the medical details.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to create medical records.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#medical');
    }

    public function reschedule(Request $request, string $medical): Response
    {
        return $this->act($medical, 'Appointment updated.', function (MedicalRecord $m) use ($request): void {
            $this->service->reschedule($m, (new MedicalValidator())->book($request->only(['medical_center', 'appointment_date', 'notes'])), $this->currentUser());
        });
    }

    public function attended(Request $request, string $medical): Response
    {
        return $this->act($medical, 'Marked as attended.', function (MedicalRecord $m) use ($request): void {
            $this->service->markAttended($m, (new MedicalValidator())->attended($request->only(['medical_date'])), $this->currentUser());
        });
    }

    public function result(Request $request, string $medical): Response
    {
        return $this->act($medical, 'Medical result recorded.', function (MedicalRecord $m) use ($request): void {
            $this->service->recordResult($m, (new MedicalValidator())->result($request->only(['result', 'report_date', 'expires_at', 'notes'])), $this->currentUser());
        });
    }

    public function destroy(string $medical): Response
    {
        return $this->act($medical, 'Medical deleted.', function (MedicalRecord $m): void {
            $this->service->delete($m, $this->currentUser());
        });
    }

    /** Shared find → run → flash → back-to-candidate wrapper for the record-level actions. */
    private function act(string $publicId, string $success, callable $do): Response
    {
        $model = $this->records->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Medical record not found.');
        }

        try {
            $do($model);
            flash('status', $success);
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the details.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to do that.');
        }

        return Response::redirect('/candidates/' . $model->candidatePublicId . '#medical');
    }
}
