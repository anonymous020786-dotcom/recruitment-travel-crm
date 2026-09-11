<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\BranchScopeResolver;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\ExportRepository;
use App\Repositories\LeadRepository;
use App\Repositories\UserRepository;
use App\Support\Application;
use App\Support\Csv;
use App\Support\ListQuery;
use App\Support\Ulid;

/**
 * Lead CSV export: the request only queues an `export_jobs` row with the
 * caller's current filters; cron/process-exports.php does the actual work and
 * notifies the requester. Never generated inline — a full-branch export can be
 * tens of thousands of rows, too slow for a web request on shared hosting.
 */
final class LeadExportService
{
    private const REPORT = 'leads';

    public function __construct(
        private readonly Application $app,
        private readonly ExportRepository $jobs,
        private readonly LeadRepository $leads,
        private readonly UserRepository $users,
        private readonly BranchScopeResolver $scopes,
        private readonly NotificationService $notify,
    ) {
    }

    /** @return array{id:int,publicId:string} */
    public function request(ListQuery $query, User $actor): array
    {
        $maxRows = (int) $this->app->config()->get('import_export.leads.export.max_rows', 50000);
        $scope = $this->scopes->resolve($actor);
        $count = $this->leads->countForExport($query, $scope);
        if ($count === 0) {
            throw new ValidationException(['filters' => ['No leads match these filters.']]);
        }
        if ($count > $maxRows) {
            throw new ValidationException(['filters' => ["That's {$count} leads — narrow the filters below {$maxRows}."]]);
        }

        $publicId = Ulid::generate();
        $id = $this->jobs->create([
            'public_id'    => $publicId,
            'report'       => self::REPORT,
            'filters_json' => json_encode(['filters' => $query->filters, 'search' => $query->search], JSON_UNESCAPED_SLASHES),
            'status'       => 'pending',
            'requested_by' => $actor->id,
        ]);

        return ['id' => $id, 'publicId' => $publicId];
    }

    /**
     * Process one claimed job (cron/process-exports.php). Resolves the
     * requester's CURRENT branch scope — if their access has narrowed since
     * they asked, the export reflects that, not a stale wider scope.
     *
     * @param array{id:int,report:string,filters_json:?string,requested_by:int} $job
     * @return int rows written
     */
    public function process(array $job): int
    {
        if ($job['report'] !== self::REPORT) {
            throw new \RuntimeException("Unknown export report [{$job['report']}].");
        }

        $requester = $this->users->findById((int) $job['requested_by']);
        if ($requester === null) {
            throw new \RuntimeException('Export requester no longer exists.');
        }
        $scope = $this->scopes->resolve($requester);

        $stored = json_decode((string) ($job['filters_json'] ?? '{}'), true);
        $stored = is_array($stored) ? $stored : [];
        $query = ListQuery::of(['filters' => $stored['filters'] ?? [], 'search' => $stored['search'] ?? '']);

        $dir = 'storage/exports';
        $relPath = $dir . '/' . Ulid::generate() . '.csv';
        $absoluteDir = $this->app->basePath($dir);
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        $handle = fopen($this->app->basePath($relPath), 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the export file for writing.');
        }

        $rowCount = 0;

        try {
            Csv::writeRow($handle, [
                'Lead number', 'Name', 'Phone', 'Alternate phone', 'Email', 'Gender', 'Date of birth',
                'City', 'State', 'Source', 'Campaign', 'Interested country', 'Interested job',
                'Experience (years)', 'Qualification', 'Salary expectation', 'Salary currency',
                'Priority', 'Status', 'Assignee', 'Created at',
            ]);

            foreach ($this->leads->cursorForExport($query, $scope) as $row) {
                Csv::writeRow($handle, [
                    $row['lead_number'], $row['name'], $row['phone'], $row['alternate_phone'] ?? '',
                    $row['email'] ?? '', $row['gender'] ?? '', $row['date_of_birth'] ?? '',
                    $row['city'] ?? '', $row['state'] ?? '', $row['source_name'] ?? '', $row['campaign'] ?? '',
                    $row['interested_country'] ?? '', $row['interested_job'] ?? '',
                    $row['experience_years'] ?? '', $row['qualification'] ?? '',
                    $row['salary_expectation'] ?? '', $row['salary_currency'] ?? '',
                    $row['priority'], $row['status_label'], $row['assigned_to_name'] ?? '', $row['created_at'],
                ]);
                $rowCount++;
            }
        } finally {
            fclose($handle);
        }

        $retentionDays = (int) $this->app->config()->get('import_export.leads.export.retention_days', 7);
        $expiresAt = new \DateTimeImmutable("+{$retentionDays} days");
        $this->jobs->markCompleted((int) $job['id'], $relPath, $rowCount, $expiresAt);

        $this->notify->notify(
            userId: $requester->id,
            type: 'export_ready',
            title: 'Your leads export is ready',
            body: "{$rowCount} row(s). Download it from My exports before " . $expiresAt->format('Y-m-d') . '.',
            linkType: 'export',
            linkId: (int) $job['id'],
            linkFragment: null,
            dedupeKey: null,
        );

        return $rowCount;
    }
}
