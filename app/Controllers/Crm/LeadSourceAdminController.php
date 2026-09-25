<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\LeadSourceAdminRepository;
use App\Services\LeadSourceAdminService;

/** Admin → Lead sources: add, rename, reorder and switch sources on or off. The rules live in LeadSourceAdminService. */
final class LeadSourceAdminController extends CrmController
{
    public function __construct(
        private readonly LeadSourceAdminRepository $sources,
        private readonly LeadSourceAdminService $service,
    ) {
    }

    public function index(): Response
    {
        return view_response('crm.admin.lead-sources.index', ['sources' => $this->sources->all(), 'protected' => LeadSourceAdminService::PROTECTED_NAME]);
    }

    public function store(Request $request): Response
    {
        try {
            $this->service->create((string) $request->input('name', ''), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), ['name' => $request->input('name', '')], '/admin/lead-sources');
        }
        flash('status', 'Source added at the end of the list.');

        return Response::redirect('/admin/lead-sources');
    }

    public function rename(Request $request, string $source): Response
    {
        try {
            $this->service->rename((int) $source, (string) $request->input('name', ''), $this->currentUser());
            flash('status', 'Source renamed.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->errors()['name'][0] ?? 'That name is not valid.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/admin/lead-sources');
    }

    public function toggle(Request $request, string $source): Response
    {
        $on = (string) $request->input('active', '') === '1';

        return $this->act(fn () => $this->service->setActive((int) $source, $on, $this->currentUser()), $on ? 'Source switched on.' : 'Source switched off — new leads no longer offer it.');
    }

    public function move(Request $request, string $source): Response
    {
        $direction = (string) $request->input('direction', '') === 'up' ? 'up' : 'down';

        return $this->act(fn () => $this->service->move((int) $source, $direction, $this->currentUser()), 'Order updated.');
    }

    /** @param callable():void $do */
    private function act(callable $do, string $success): Response
    {
        try {
            $do();
            flash('status', $success);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/admin/lead-sources');
    }
}
