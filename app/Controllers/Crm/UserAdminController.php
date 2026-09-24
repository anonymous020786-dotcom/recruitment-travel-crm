<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\LoginHistoryRepository;
use App\Repositories\SessionRepository;
use App\Repositories\UserAdminRepository;
use App\Services\UserAdminService;
use App\Support\ListQuery;

/** Admin → Users: create and manage staff accounts. The rules (who may touch whom) live in UserAdminService. */
final class UserAdminController extends CrmController
{
    public function __construct(
        private readonly UserAdminRepository $users,
        private readonly UserAdminService $service,
        private readonly LoginHistoryRepository $logins,
        private readonly SessionRepository $sessions,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, UserAdminRepository::SORT, UserAdminRepository::FILTER_KEYS, 'name', 'asc');

        return view_response('crm.admin.users.index', [
            'page' => $this->users->paginate($query), 'query' => $query,
            'counts' => $this->users->counts(), 'roles' => $this->users->roles(),
        ]);
    }

    public function create(): Response
    {
        return view_response('crm.admin.users.form', $this->formData(null));
    }

    public function store(Request $request): Response
    {
        try {
            $r = $this->service->create($request->all(), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/admin/users/create');
        } catch (DomainRuleException $e) {
            return redirect_with_errors(['form' => [$e->getMessage()]], $request->all(), '/admin/users/create');
        }
        flash('status', 'Account created. Give the person their temporary password below — it is shown only once.');
        session()?->flash('temporary_password', $r['temporary_password']);

        return Response::redirect('/admin/users/' . $r['public_id']);
    }

    public function show(string $user): Response
    {
        $row = $this->find($user);
        $roles = array_column($this->users->roles(), 'label', 'id');
        $branchNames = array_column($this->users->branches(), 'name', 'id');

        return view_response('crm.admin.users.show', [
            'u' => $row,
            'branches' => array_values(array_filter(array_map(static fn (int $id): ?string => $branchNames[$id] ?? null, $row['branch_ids']))),
            'logins' => $this->logins->recentForUser((int) $row['id'], 10),
            'sessionCount' => count($this->sessions->forUser((int) $row['id'])),
            'canManage' => can('users.manage') && ($row['role_name'] !== 'super_admin' || $this->currentUser()->isSuperAdmin()),
            'isSelf' => (int) $row['id'] === $this->currentUser()->id,
            'roleLabel' => $roles[(int) $row['role_id']] ?? $row['role_label'],
        ]);
    }

    public function edit(string $user): Response
    {
        return view_response('crm.admin.users.form', $this->formData($this->find($user)));
    }

    public function update(Request $request, string $user): Response
    {
        $row = $this->find($user);
        try {
            $this->service->update($user, $request->all(), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), "/admin/users/{$user}/edit");
        } catch (DomainRuleException $e) {
            return redirect_with_errors(['form' => [$e->getMessage()]], $request->all(), "/admin/users/{$user}/edit");
        }
        flash('status', 'Changes saved.');

        return Response::redirect('/admin/users/' . $row['public_id']);
    }

    public function status(Request $request, string $user): Response
    {
        return $this->act($user, fn () => $this->service->setActive($user, (string) $request->input('active', '') === '1', $this->currentUser()), (string) $request->input('active', '') === '1' ? 'Account reactivated.' : 'Account deactivated and signed out everywhere.');
    }

    public function unlock(string $user): Response
    {
        return $this->act($user, fn () => $this->service->unlock($user, $this->currentUser()), 'Account unlocked.');
    }

    public function resetLink(Request $request, string $user): Response
    {
        return $this->act($user, fn () => $this->service->sendResetLink($user, $request, $this->currentUser()), 'A password reset link has been emailed (if the address is deliverable).');
    }

    public function temporaryPassword(string $user): Response
    {
        return $this->act($user, function () use ($user): void {
            $temporary = $this->service->issueTemporaryPassword($user, $this->currentUser());
            session()?->flash('temporary_password', $temporary);
        }, 'A new temporary password was set and the person was signed out. It is shown below, once.');
    }

    public function signOut(string $user): Response
    {
        return $this->act($user, fn () => $this->service->signOutEverywhere($user, $this->currentUser()), 'Signed out everywhere.');
    }

    public function resetTwoFactor(string $user): Response
    {
        return $this->act($user, fn () => $this->service->resetTwoFactor($user, $this->currentUser()), 'Two-factor authentication was removed; they can enrol again at next sign-in.');
    }

    // ---- internals -----------------------------------------------------------------------------

    /** @param callable():void $do */
    private function act(string $user, callable $do, string $success): Response
    {
        $row = $this->find($user);
        try {
            $do();
            flash('status', $success);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/admin/users/' . $row['public_id']);
    }

    /** @return array<string,mixed> */
    private function find(string $publicId): array
    {
        $row = $this->users->findByPublicId($publicId);
        if ($row === null) {
            abort(404, 'User not found.');
        }

        return $row;
    }

    /** @param array<string,mixed>|null $u @return array<string,mixed> */
    private function formData(?array $u): array
    {
        return ['u' => $u, 'roles' => $this->users->roles(), 'branches' => $this->users->branches(), 'actorIsSuper' => $this->currentUser()->isSuperAdmin(), 'actorIsAdmin' => in_array($this->currentUser()->roleName, ['super_admin', 'admin'], true)];
    }
}
