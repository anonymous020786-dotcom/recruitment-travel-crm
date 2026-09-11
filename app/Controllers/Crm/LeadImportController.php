<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\ImportBatch;
use App\Repositories\ImportRepository;
use App\Services\LeadImportService;
use App\Support\Application;

/**
 * CSV lead import: upload -> preview (column mapping) -> confirm (processed
 * synchronously) -> report. A batch is visible only to the user who staged it.
 */
final class LeadImportController extends CrmController
{
    public function __construct(
        private readonly LeadImportService $service,
        private readonly ImportRepository $repo,
        private readonly Application $app,
    ) {
    }

    public function create(): Response
    {
        return view_response('crm.leads.import-create', [
            'fields' => LeadImportService::FIELDS,
            'maxRows' => (int) config('import_export.leads.import.max_rows', 500),
            'maxKb' => (int) config('import_export.leads.import.max_kb', 2048),
        ]);
    }

    public function store(Request $request): Response
    {
        $file = $request->file('file');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return redirect_with_errors(['file' => [$this->uploadErrorMessage($file)]], [], '/leads/import');
        }

        $maxKb = (int) config('import_export.leads.import.max_kb', 2048);
        if ((int) $file['size'] > $maxKb * 1024) {
            return redirect_with_errors(['file' => ["The file is larger than {$maxKb} KB."]], [], '/leads/import');
        }
        if (strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            return redirect_with_errors(['file' => ['Upload a .csv file.']], [], '/leads/import');
        }

        $branchId = $request->input('branch_id');
        $branchId = ($branchId !== null && ctype_digit((string) $branchId)) ? (int) $branchId : null;

        try {
            $staged = $this->service->stage($file, $this->currentUser(), $branchId);
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], '/leads/import');
        }

        flash('status', "{$staged['batch']->totalRows} row(s) staged. Check the column mapping below.");

        return Response::redirect('/leads/import/' . $staged['batch']->publicId);
    }

    public function preview(Request $request, string $batch): Response
    {
        $model = $this->find($batch);
        if (!$model->isPreviewed()) {
            return Response::redirect('/leads/import/' . $model->publicId . '/report');
        }

        return view_response('crm.leads.import-preview', [
            'batch'   => $model,
            'sample'  => $this->repo->sampleRows($model->id, 10),
            'fields'  => LeadImportService::FIELDS,
        ]);
    }

    public function confirm(Request $request, string $batch): Response
    {
        $model = $this->find($batch);

        $mapping = [];
        foreach ((array) $request->input('field', []) as $index => $field) {
            if (is_string($field) && $field !== '') {
                $mapping[(string) $index] = $field;
            }
        }

        try {
            $this->service->confirm($model, $mapping, $request->boolean('import_duplicates'), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], '/leads/import/' . $model->publicId);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/leads/import/' . $model->publicId . '/report');
        }

        return Response::redirect('/leads/import/' . $model->publicId . '/report');
    }

    public function report(Request $request, string $batch): Response
    {
        return view_response('crm.leads.import-report', ['batch' => $this->find($batch)]);
    }

    public function downloadReport(Request $request, string $batch): Response
    {
        $model = $this->find($batch);
        if ($model->reportPath === null) {
            abort(404, 'No report for this import.');
        }
        $absolute = $this->app->basePath($model->reportPath);
        if (!is_file($absolute)) {
            abort(404, 'Report file no longer available.');
        }

        return Response::stream(static function () use ($absolute): void {
            readfile($absolute);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $model->publicId . '-errors.csv"',
            'Content-Length' => (string) filesize($absolute),
        ]);
    }

    // ---- internals -----------------------------------------------

    private function find(string $publicId): ImportBatch
    {
        $model = $this->repo->findBatchByPublicId($publicId, $this->currentUser()->id);
        if ($model === null) {
            abort(404, 'Import not found.');
        }

        return $model;
    }

    private function uploadErrorMessage(?array $file): string
    {
        return match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a CSV file to upload.',
            default => 'The file could not be uploaded.',
        };
    }
}
