<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Employer;
use App\Repositories\CandidateRepository;
use App\Repositories\EmployerContactRepository;
use App\Repositories\EmployerRepository;
use App\Services\EmployerService;
use App\Support\Db;
use App\Support\ListQuery;
use App\Validators\EmployerContactValidator;
use App\Validators\EmployerValidator;

/** Employer screens: list, create/edit, profile with contacts. Jobs attach in Step 5.2. */
final class EmployerController extends CrmController
{
    private const FIELDS = [
        'company_name', 'country', 'city', 'address', 'industry', 'website',
        'license_number', 'license_expiry', 'status', 'account_owner', 'notes',
    ];

    public function __construct(
        private readonly EmployerRepository $employers,
        private readonly EmployerContactRepository $contacts,
        private readonly CandidateRepository $candidates,
        private readonly EmployerService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, EmployerRepository::SORT, EmployerRepository::FILTER_KEYS, 'created_at');

        return view_response('crm.employers.index', [
            'page'      => $this->employers->paginate($query, $this->scope()),
            'query'     => $query,
            'countries' => $this->countryOptions(),
            'canCreate' => can('employers.create'),
        ]);
    }

    public function create(): Response
    {
        return view_response('crm.employers.create', $this->formData());
    }

    public function store(Request $request): Response
    {
        try {
            $data = (new EmployerValidator())->validate($request->only(self::FIELDS));
            $branch = $request->input('branch_id');
            $employer = $this->service->create(
                $data,
                $this->currentUser(),
                ($branch !== null && ctype_digit((string) $branch)) ? (int) $branch : null,
            );
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/employers/create');
        }

        flash('status', "Employer {$employer->employerNumber} created.");

        return Response::redirect('/employers/' . $employer->publicId);
    }

    public function show(string $employer): Response
    {
        $model = $this->find($employer);
        authorize('view', $model);

        return view_response('crm.employers.show', [
            'employer'   => $model,
            'contacts'   => $this->contacts->forEmployer($model->id),
            'canEdit'    => can('update', $model),
            'canDelete'  => can('delete', $model),
            'canContacts' => can('manageContacts', $model),
        ]);
    }

    public function edit(string $employer): Response
    {
        $model = $this->find($employer);
        authorize('update', $model);

        return view_response('crm.employers.edit', ['employer' => $model] + $this->formData());
    }

    public function update(Request $request, string $employer): Response
    {
        $model = $this->find($employer);
        authorize('update', $model);

        try {
            $data = (new EmployerValidator())->validate($request->only(self::FIELDS));
            $this->service->update($model, $data, $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/employers/' . $model->publicId . '/edit');
        }

        flash('status', 'Employer updated.');

        return Response::redirect('/employers/' . $model->publicId);
    }

    public function destroy(string $employer): Response
    {
        $model = $this->find($employer);

        try {
            $this->service->delete($model, $this->currentUser());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to delete employers.');

            return Response::redirect('/employers/' . $model->publicId);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/employers');
        }

        flash('status', 'Employer deleted.');

        return Response::redirect('/employers');
    }

    public function storeContact(Request $request, string $employer): Response
    {
        $model = $this->find($employer);

        try {
            $data = (new EmployerContactValidator())->validate($request->only(['name', 'designation', 'email', 'phone', 'is_primary']));
            $this->service->addContact($model, $data, $this->currentUser());
            flash('status', 'Contact added.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not add that contact.');
        }

        return Response::redirect('/employers/' . $model->publicId . '#contacts');
    }

    public function updateContact(Request $request, string $employer, string $contact): Response
    {
        $model = $this->find($employer);

        try {
            $data = (new EmployerContactValidator())->validate($request->only(['name', 'designation', 'email', 'phone', 'is_primary']));
            $this->service->updateContact($model, (int) $contact, $data, $this->currentUser());
            flash('status', 'Contact updated.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not update that contact.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/employers/' . $model->publicId . '#contacts');
    }

    public function destroyContact(string $employer, string $contact): Response
    {
        $model = $this->find($employer);

        try {
            $this->service->removeContact($model, (int) $contact, $this->currentUser());
            flash('status', 'Contact removed.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/employers/' . $model->publicId . '#contacts');
    }

    // ---- internals -------------------------------------------------

    private function find(string $publicId): Employer
    {
        $model = $this->employers->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Employer not found.');
        }

        return $model;
    }

    /** @return array<string,mixed> */
    private function formData(): array
    {
        return [
            'countries' => $this->countryOptions(),
            'owners'    => $this->candidates->assignableCounselors($this->scope()),
        ];
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
