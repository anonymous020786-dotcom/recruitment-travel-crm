<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Services\AuditLogService;

/** Admin → Audit log: a read-only, filterable view of who did what. */
final class AuditLogController extends CrmController
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function index(Request $request): Response
    {
        $page = max(1, min((int) $request->query('page', '1'), 200));
        $input = $request->only(['module', 'q', 'from', 'to', 'record_type', 'record_id']);
        $errors = [];
        try {
            $filters = $this->audit->filters($input);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $filters = $this->audit->filters([]);   // show the default view under the error message
        }
        $result = $this->audit->page($this->currentUser(), $filters, $page);

        return view_response('crm.admin.audit.index', [
            'rows' => $result['rows'], 'total' => $result['total'], 'capped' => $result['capped'], 'page' => $page, 'perPage' => AuditLogService::PER_PAGE,
            'filters' => $filters, 'errors' => $errors, 'modules' => $this->audit->modules(),
            'submitted' => $input,
        ]);
    }
}
