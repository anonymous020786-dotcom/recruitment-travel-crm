<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\BranchAdminRepository;
use App\Services\BranchAdminService;

/** Admin → Branches: create and maintain branches. The rules live in BranchAdminService. */
final class BranchAdminController extends CrmController
{
    private const FIELDS = ['name', 'code', 'address_line1', 'address_line2', 'city', 'state', 'country', 'phone', 'email'];

    public function __construct(
        private readonly BranchAdminRepository $branches,
        private readonly BranchAdminService $service,
    ) {
    }

    public function index(): Response
    {
        return view_response('crm.admin.branches.index', ['branches' => $this->branches->all()]);
    }

    public function create(): Response
    {
        return view_response('crm.admin.branches.form', ['branch' => null]);
    }

    public function store(Request $request): Response
    {
        try {
            $this->service->create($request->only(self::FIELDS), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(self::FIELDS), '/admin/branches/create');
        }
        flash('status', 'Branch created. Add people to it in Admin → Users.');

        return Response::redirect('/admin/branches');
    }

    public function edit(string $branch): Response
    {
        return view_response('crm.admin.branches.form', ['branch' => $this->find($branch)]);
    }

    public function update(Request $request, string $branch): Response
    {
        $row = $this->find($branch);
        try {
            $this->service->update($branch, $request->only(self::FIELDS), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(self::FIELDS), '/admin/branches/' . $row['public_id'] . '/edit');
        }
        flash('status', 'Branch saved.');

        return Response::redirect('/admin/branches');
    }

    public function deactivate(string $branch): Response
    {
        return $this->move($branch, fn () => $this->service->deactivate($branch, $this->currentUser()), 'Branch deactivated. Its records are kept.');
    }

    public function reactivate(string $branch): Response
    {
        return $this->move($branch, fn () => $this->service->reactivate($branch, $this->currentUser()), 'Branch reactivated.');
    }

    // ---- internals -----------------------------------------------------------------------------

    /** @param callable():void $do */
    private function move(string $branch, callable $do, string $success): Response
    {
        $this->find($branch);
        try {
            $do();
            flash('status', $success);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/admin/branches');
    }

    /** @return array<string,mixed> */
    private function find(string $publicId): array
    {
        $row = $this->branches->find($publicId);
        if ($row === null) {
            abort(404, 'Branch not found.');
        }

        return $row;
    }
}
