<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\RoleAdminRepository;
use App\Services\RoleAdminService;

/** Admin → Roles: see and tune which permissions each role holds. The rules live in RoleAdminService. */
final class RoleAdminController extends CrmController
{
    public function __construct(
        private readonly RoleAdminRepository $roles,
        private readonly RoleAdminService $service,
    ) {
    }

    public function index(): Response
    {
        return view_response('crm.admin.roles.index', ['roles' => $this->roles->roles()]);
    }

    public function show(string $role): Response
    {
        $row = $this->find($role);

        return view_response('crm.admin.roles.show', [
            'role' => $row,
            'catalogue' => $this->roles->catalogue(),
            'granted' => $this->roles->grantedNames($row['id']),
            'defaults' => $this->service->defaultsFor($row['name']),
            'editable' => $row['name'] !== 'super_admin' && $this->currentUser()->isSuperAdmin(),
        ]);
    }

    public function update(Request $request, string $role): Response
    {
        $row = $this->find($role);
        $names = $request->input('permissions', []);
        try {
            $diff = $this->service->update($row['name'], is_array($names) ? array_values($names) : [], $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], '/admin/roles/' . $row['name']);
        } catch (DomainRuleException $e) {
            return redirect_with_errors(['form' => [$e->getMessage()]], ['permissions' => $names], '/admin/roles/' . $row['name']);
        }
        flash('status', $this->summary($diff, $row['label']));

        return Response::redirect('/admin/roles/' . $row['name']);
    }

    public function reset(string $role): Response
    {
        $row = $this->find($role);
        try {
            $diff = $this->service->reset($row['name'], $this->currentUser());
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/admin/roles/' . $row['name']);
        }
        flash('status', $diff['added'] === [] && $diff['removed'] === [] ? $row['label'] . ' already matches the default.' : 'Reset to the default. ' . $this->summary($diff, $row['label']));

        return Response::redirect('/admin/roles/' . $row['name']);
    }

    // ---- internals -----------------------------------------------------------------------------

    /** @return array{id:int,name:string,label:string,description:?string,is_system:bool,permissions:int,users:int} */
    private function find(string $name): array
    {
        $row = $this->roles->findByName($name);
        if ($row === null) {
            abort(404, 'Role not found.');
        }

        return $row;
    }

    /** @param array{added:list<string>,removed:list<string>} $diff */
    private function summary(array $diff, string $label): string
    {
        if ($diff['added'] === [] && $diff['removed'] === []) {
            return 'No changes to ' . $label . '.';
        }

        return $label . ': ' . count($diff['added']) . ' permission' . (count($diff['added']) === 1 ? '' : 's') . ' added, '
            . count($diff['removed']) . ' removed. This applies to everyone in the role at their next click.';
    }
}
