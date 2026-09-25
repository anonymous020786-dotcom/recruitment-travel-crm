<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Services\UserPermissionService;

/** Admin → Users → Permissions: allow or deny single permissions for one person on top of their role (super admin only). */
final class UserPermissionController extends CrmController
{
    public function __construct(private readonly UserPermissionService $service)
    {
    }

    public function show(string $user): Response
    {
        try {
            $panel = $this->service->panel($user, $this->currentUser());
        } catch (DomainRuleException $e) {
            abort($e->httpStatus(), $e->getMessage());
        }

        return view_response('crm.admin.users.permissions', $panel)->withHeader('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $user): Response
    {
        $back = '/admin/users/' . $user . '/permissions';
        try {
            $this->service->set(
                $user,
                (string) $request->input('permission', ''),
                (string) $request->input('effect', ''),
                (string) $request->input('expires_on', ''),
                (string) $request->input('note', ''),
                $this->currentUser(),
            );
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(['permission', 'effect', 'expires_on', 'note']), $back);
        } catch (DomainRuleException $e) {
            return $this->refused($e, $back);
        }
        flash('status', 'Saved. It applies at their next click.');

        return Response::redirect($back);
    }

    public function remove(Request $request, string $user): Response
    {
        $back = '/admin/users/' . $user . '/permissions';
        try {
            $done = $this->service->remove($user, (string) $request->input('permission', ''), $this->currentUser());
        } catch (DomainRuleException $e) {
            return $this->refused($e, $back);
        }
        flash('status', $done ? 'Override removed — their role decides again.' : 'That override is already gone.');

        return Response::redirect($back);
    }

    public function reset(string $user): Response
    {
        $back = '/admin/users/' . $user . '/permissions';
        try {
            $n = $this->service->clear($user, $this->currentUser());
        } catch (DomainRuleException $e) {
            return $this->refused($e, $back);
        }
        flash('status', $n > 0 ? 'All overrides removed — they now have exactly what their role gives.' : 'There were no overrides.');

        return Response::redirect($back);
    }

    private function refused(DomainRuleException $e, string $back): Response
    {
        if ($e->httpStatus() === 404) {
            abort(404, $e->getMessage());
        }

        return redirect_with_errors(['form' => [$e->getMessage()]], [], $back);
    }
}
