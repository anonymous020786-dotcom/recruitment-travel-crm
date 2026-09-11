<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ExportRepository;
use App\Repositories\LeadRepository;
use App\Services\LeadExportService;
use App\Support\Application;
use App\Support\ListQuery;

/**
 * Lead CSV export: queues a job (processed by cron/process-exports.php) and
 * lets the requester come back to download it once ready. A job is visible
 * only to the user who requested it.
 */
final class LeadExportController extends CrmController
{
    public function __construct(
        private readonly LeadExportService $service,
        private readonly ExportRepository $jobs,
        private readonly Application $app,
    ) {
    }

    public function store(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, LeadRepository::SORT, LeadRepository::FILTER_KEYS, 'created_at');

        try {
            $this->service->request($query, $this->currentUser());
            flash('status', "Export queued. We'll notify you here when it's ready — check My exports.");
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not queue the export.');
        }

        return back();
    }

    public function index(): Response
    {
        return view_response('crm.exports.index', [
            'jobs' => $this->jobs->forUser($this->currentUser()->id, 20),
        ]);
    }

    public function download(Request $request, string $job): Response
    {
        $model = $this->jobs->findByPublicId($job);
        if ($model === null || $model->requestedBy !== $this->currentUser()->id) {
            abort(404, 'Export not found.');
        }
        if (!$model->isReady() || $model->storagePath === null) {
            abort(404, 'This export is not ready or has expired.');
        }

        $absolute = $this->app->basePath($model->storagePath);
        if (!is_file($absolute)) {
            abort(404, 'Export file no longer available.');
        }

        return Response::stream(static function () use ($absolute): void {
            readfile($absolute);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="leads-export-' . $model->publicId . '.csv"',
            'Content-Length' => (string) filesize($absolute),
        ]);
    }
}
