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
use App\Models\VisaApplication;
use App\Repositories\ApplicationRepository;
use App\Repositories\CandidateRepository;
use App\Repositories\VisaHistoryRepository;
use App\Repositories\VisaRepository;
use App\Services\VisaService;
use App\Support\Db;
use App\Support\ListQuery;
use App\Validators\VisaValidator;

/** Visa screens: the register, a visa's profile with history, and create / edit / status / delete actions. */
final class VisaController extends CrmController
{
    private const DETAIL_FIELDS = ['country', 'visa_type', 'visa_number', 'reference_number', 'sponsor', 'notes'];

    public function __construct(
        private readonly VisaRepository $visas,
        private readonly VisaHistoryRepository $history,
        private readonly CandidateRepository $candidates,
        private readonly ApplicationRepository $applications,
        private readonly VisaService $service,
        private readonly StatusMachine $statuses,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, VisaRepository::SORT, VisaRepository::FILTER_KEYS, 'created_at');

        return view_response('crm.visa.index', [
            'page'      => $this->visas->paginate($query, $this->scope()),
            'query'     => $query,
            'statuses'  => $this->statuses->states('visa'),
            'countries' => $this->countryOptions(),
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
            $visa = $this->service->create($model, $application, (new VisaValidator())->details($request->only(self::DETAIL_FIELDS)), $this->currentUser());
            flash('status', 'Visa application started.');

            return Response::redirect('/visa/' . $visa->publicId);
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the visa details.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to create visa applications.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#visa');
    }

    public function show(string $visa): Response
    {
        $model = $this->find($visa);
        authorize('view', $model);

        return view_response('crm.visa.show', [
            'visa'         => $model,
            'history'      => $this->history->forVisa($model->id),
            'countries'    => $this->countryOptions(),
            'canEdit'      => can('edit', $model) && !$model->isClosed(),
            'canStatus'    => can('changeStatus', $model),
            'canOverride'  => can('overrideStatus', $model),
            'canDelete'    => can('delete', $model) && $model->status === 'not_started',
            'nextStatuses' => $this->statuses->transitionsFrom('visa', $model->status),
            'allStatuses'  => array_values(array_diff($this->statuses->states('visa'), [$model->status])),
        ]);
    }

    public function update(Request $request, string $visa): Response
    {
        $model = $this->find($visa);

        try {
            $this->service->update(
                $model,
                (new VisaValidator())->details($request->only(self::DETAIL_FIELDS)),
                $this->currentUser(),
                (int) $request->input('record_version', $model->recordVersion),
            );
            flash('status', 'Visa application updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the visa details.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This visa application changed just now. Please review and try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to edit visa applications.');
        }

        return Response::redirect('/visa/' . $model->publicId);
    }

    public function changeStatus(Request $request, string $visa): Response
    {
        $model = $this->find($visa);

        try {
            $this->service->changeStatus(
                $model,
                (new VisaValidator())->status($request->only(['status', 'reason', 'visa_number', 'submission_date', 'approval_date', 'expiry_date'])),
                $this->currentUser(),
                (int) $request->input('record_version', $model->recordVersion),
                $request->boolean('override'),
            );
            flash('status', 'Visa status updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not change the status.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This visa application changed just now. Please review and try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to make that status change.');
        }

        return Response::redirect('/visa/' . $model->publicId);
    }

    public function destroy(string $visa): Response
    {
        $model = $this->find($visa);

        try {
            $this->service->delete($model, $this->currentUser());
            flash('status', 'Visa application deleted.');

            return Response::redirect('/candidates/' . $model->candidatePublicId . '#visa');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to delete visa applications.');
        }

        return Response::redirect('/visa/' . $model->publicId);
    }

    private function find(string $publicId): VisaApplication
    {
        $model = $this->visas->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Visa application not found.');
        }

        return $model;
    }

    /** @return array<string,string> */
    private function countryOptions(): array
    {
        $out = [];
        foreach (app(Db::class)->select('SELECT code, name FROM countries WHERE is_active = 1 ORDER BY is_gcc DESC, name') as $r) {
            $out[(string) $r['code']] = (string) $r['name'];
        }

        return $out;
    }
}
