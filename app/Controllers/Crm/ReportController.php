<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Audit\AuditService;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Services\ReportService;

/** Reports: the catalogue, a filterable report page (with a print view) and a streamed CSV download. */
final class ReportController extends CrmController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditService $audit,
    ) {
    }

    public function index(): Response
    {
        return view_response('crm.reports.index', ['catalog' => $this->reports->catalogFor($this->currentUser())]);
    }

    public function show(Request $request, string $report): Response
    {
        $user = $this->currentUser();
        $def = $this->reports->definition($report, $user);
        if ($def === null) {
            abort(404, 'Report not found.');
        }

        try {
            $filters = $this->reports->filters($report, $request->only(['from', 'to', 'days']));
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Check the filters.');

            return Response::redirect('/reports/' . $report);
        }

        $page = $this->reports->page($report, $filters, $this->scope(), $user);
        $data = [
            'key' => $report, 'def' => $def, 'filters' => $filters,
            'rows' => $page['rows'], 'truncated' => $page['truncated'], 'limit' => ReportService::VIEW_ROWS,
            'canExport' => can('reports.export'),
        ];

        return $request->query('print') !== null
            ? view_response('crm.reports.print', $data + ['generatedBy' => $user->name])
            : view_response('crm.reports.show', $data);
    }

    /** Streams the whole report (up to the export cap) without building it in memory. */
    public function csv(Request $request, string $report): Response
    {
        $user = $this->currentUser();
        $def = $this->reports->definition($report, $user);
        if ($def === null) {
            abort(404, 'Report not found.');
        }

        try {
            $filters = $this->reports->filters($report, $request->only(['from', 'to', 'days']));
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Check the filters.');

            return Response::redirect('/reports/' . $report);
        }

        $scope = $this->scope();
        $name = 'report-' . $report . '-' . $filters['from'] . '-' . $filters['to'] . '.csv';

        return Response::stream(function () use ($report, $filters, $scope, $user): void {
            $out = fopen('php://output', 'wb');
            $count = $this->reports->exportCsv($report, $filters, $scope, $user, $out);
            fclose($out);
            $this->audit->log('exported', 'reports', 'report', 0, null, ['report' => $report, 'filters' => $filters, 'rows' => $count], null, $user);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'private, no-store',
            'X-Robots-Tag'        => 'noindex, nofollow',
        ]);
    }
}
