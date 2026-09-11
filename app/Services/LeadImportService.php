<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScopeResolver;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\ImportBatch;
use App\Models\User;
use App\Repositories\ImportRepository;
use App\Repositories\LeadRepository;
use App\Repositories\UserRepository;
use App\Support\Application;
use App\Support\Csv;
use App\Support\Ulid;
use App\Validators\LeadValidator;

/**
 * CSV lead import: upload -> preview (auto-suggested column mapping) -> confirm
 * (processed synchronously, bounded by import_export.leads.import.max_rows so
 * a request never runs long enough to risk a shared-host execution-time
 * limit). Each row goes through the exact same LeadValidator + LeadService
 * that a manual create does, so an imported lead can never bypass a business
 * rule a hand-typed one is subject to.
 */
final class LeadImportService
{
    /** field key => label shown in the mapping UI, in display order */
    public const FIELDS = [
        'name'               => 'Name',
        'phone'              => 'Phone',
        'alternate_phone'    => 'Alternate phone',
        'email'              => 'Email',
        'gender'             => 'Gender (male/female/other/undisclosed)',
        'date_of_birth'      => 'Date of birth (YYYY-MM-DD)',
        'city'               => 'City',
        'state'              => 'State',
        'source'             => 'Source (by name)',
        'campaign'           => 'Campaign',
        'interested_country' => 'Interested country (2-letter code)',
        'interested_job'     => 'Interested job',
        'experience_years'   => 'Experience (years)',
        'qualification'      => 'Qualification',
        'salary_expectation' => 'Salary expectation',
        'salary_currency'    => 'Salary currency (3-letter)',
        'priority'           => 'Priority (low/medium/high/urgent)',
        'assignee_email'     => 'Assignee (by email)',
        'notes'              => 'Notes',
    ];

    private const REQUIRED_FIELDS = ['name', 'phone'];

    /** normalised-header synonym => field key, checked after an exact match */
    private const SYNONYMS = [
        'fullname' => 'name', 'leadname' => 'name', 'candidatename' => 'name',
        'mobile' => 'phone', 'mobilenumber' => 'phone', 'contactnumber' => 'phone', 'phonenumber' => 'phone', 'primaryphone' => 'phone',
        'altphone' => 'alternate_phone', 'secondaryphone' => 'alternate_phone', 'whatsapp' => 'alternate_phone', 'whatsappnumber' => 'alternate_phone',
        'emailaddress' => 'email', 'mail' => 'email',
        'dob' => 'date_of_birth', 'birthdate' => 'date_of_birth',
        'country' => 'interested_country', 'destination' => 'interested_country', 'destinationcountry' => 'interested_country',
        'job' => 'interested_job', 'jobtitle' => 'interested_job', 'position' => 'interested_job', 'occupation' => 'interested_job',
        'experience' => 'experience_years', 'yearsofexperience' => 'experience_years', 'totalexperience' => 'experience_years',
        'salary' => 'salary_expectation', 'expectedsalary' => 'salary_expectation',
        'currency' => 'salary_currency',
        'assignedto' => 'assignee_email', 'assignee' => 'assignee_email', 'owner' => 'assignee_email',
        'counselor' => 'assignee_email', 'counsellor' => 'assignee_email',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly ImportRepository $repo,
        private readonly LeadRepository $leads,
        private readonly UserRepository $users,
        private readonly LeadService $leadService,
        private readonly BranchScopeResolver $scopes,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array{name:string,tmp_name:string,error:int,size:int} $file
     * @return array{batch:ImportBatch,headers:list<string>}
     */
    public function stage(array $file, User $actor, ?int $branchId): array
    {
        $branchId ??= $actor->primaryBranchId;
        $scope = $this->scopes->resolve($actor);
        if ($branchId === null || !$scope->contains($branchId)) {
            throw new ValidationException(['branch_id' => ['Select a valid branch for this import.']]);
        }

        [$headers, $rows] = $this->parse($file);
        if (count($rows) === 0) {
            throw new ValidationException(['file' => ['The file has no data rows.']]);
        }

        $storedPath = $this->store($file, 'storage/imports');

        $batchId = $this->repo->createBatch([
            'public_id'     => Ulid::generate(),
            'entity'        => 'leads',
            'original_name' => mb_substr(basename($file['name']), 0, 200),
            'storage_path'  => $storedPath,
            'total_rows'    => count($rows),
            'status'        => 'previewed',
            'mapping_json'  => json_encode(
                ['headers' => $headers, 'map' => $this->suggestMapping($headers)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'branch_id'     => $branchId,
            'created_by'    => $actor->id,
        ]);

        $this->repo->insertRows($batchId, $rows);

        $batch = $this->repo->findBatch($batchId, $actor->id);
        if ($batch === null) {
            throw new \RuntimeException('Import batch vanished after creation.');
        }

        return ['batch' => $batch, 'headers' => $headers];
    }

    /**
     * @param array<int|string,string> $mapping column index (as string) => field key, blank/absent = ignore
     */
    public function confirm(ImportBatch $batch, array $mapping, bool $importDuplicates, User $actor): ImportBatch
    {
        if (!$batch->isPreviewed()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This import has already been processed.', []);
        }

        $mapping = array_filter($mapping, static fn ($f) => is_string($f) && $f !== '' && isset(self::FIELDS[$f]));
        $mappedFields = array_values($mapping);
        $missing = array_diff(self::REQUIRED_FIELDS, $mappedFields);
        if ($missing !== []) {
            $labels = array_map(static fn (string $f): string => self::FIELDS[$f], $missing);
            throw new ValidationException(['mapping' => ['Map a column to: ' . implode(', ', $labels) . '.']]);
        }

        $this->repo->setMapping($batch->id, $batch->headers, $mapping);
        $this->repo->setStatus($batch->id, 'processing');

        $scope = $this->scopes->resolve($actor);
        $sourceByName = [];
        foreach ($this->leads->sourceOptions() as $s) {
            $sourceByName[$this->normalise((string) $s['name'])] = (int) $s['id'];
        }
        $assignableIds = array_column($this->leads->assignableUsers($scope), 'id');

        $validator = new LeadValidator();
        $imported = $skipped = $failed = 0;

        foreach ($this->repo->cursorRows($batch->id) as $row) {
            $raw = json_decode((string) $row['raw_json'], true);
            $raw = is_array($raw) ? $raw : [];

            $data = [];
            foreach ($mapping as $index => $field) {
                $data[$field] = trim((string) ($raw[(string) $index] ?? ''));
            }

            $data = $this->resolveLookups($data, $sourceByName, $assignableIds);

            try {
                $validated = $validator->validate($data, 'create');
            } catch (ValidationException $e) {
                $this->repo->markRow((int) $row['id'], 'failed', $e->first(), null);
                $failed++;
                continue;
            }

            try {
                $lead = $this->leadService->create($validated, $actor, $batch->branchId, confirmedNotDuplicate: $importDuplicates);
                $this->repo->markRow((int) $row['id'], 'imported', null, $lead->id);
                $imported++;
            } catch (DomainRuleException $e) {
                if ($e->ruleCode() === DomainRuleException::DUPLICATE_LEAD) {
                    $this->repo->markRow((int) $row['id'], 'skipped', 'Likely duplicate of an existing lead.', null);
                    $skipped++;
                } else {
                    $this->repo->markRow((int) $row['id'], 'failed', $e->getMessage(), null);
                    $failed++;
                }
            } catch (ValidationException $e) {
                $this->repo->markRow((int) $row['id'], 'failed', $e->first(), null);
                $failed++;
            }
        }

        $reportPath = ($skipped + $failed) > 0 ? $this->writeReport($batch) : null;
        $this->repo->finish($batch->id, $imported, $skipped, $failed, 'completed', $reportPath);

        $this->audit->log('import_completed', 'leads', 'import_batch', $batch->id, null, [
            'imported' => $imported, 'skipped' => $skipped, 'failed' => $failed,
        ], null, $actor);

        return $this->repo->findBatch($batch->id, $actor->id) ?? $batch;
    }

    // ---- lookups (never fail a row — an unresolved source/assignee is just dropped) ----

    /**
     * @param array<string,string> $data
     * @param array<string,int> $sourceByName
     * @param list<int> $assignableIds
     * @return array<string,mixed>
     */
    private function resolveLookups(array $data, array $sourceByName, array $assignableIds): array
    {
        if (($source = trim((string) ($data['source'] ?? ''))) !== '') {
            $id = $sourceByName[$this->normalise($source)] ?? null;
            if ($id !== null) {
                $data['source_id'] = $id;
            }
        }
        unset($data['source']);

        if (($email = trim((string) ($data['assignee_email'] ?? ''))) !== '') {
            $user = $this->users->findByEmail($email);
            if ($user !== null && in_array($user->id, $assignableIds, true)) {
                $data['assigned_to'] = $user->id;
            }
        }
        unset($data['assignee_email']);

        if (($priority = strtolower(trim((string) ($data['priority'] ?? '')))) !== ''
            && in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            $data['priority'] = $priority;
        } else {
            $data['priority'] = 'medium';
        }

        foreach (['alternate_phone', 'email', 'gender', 'date_of_birth', 'city', 'state', 'campaign',
                  'interested_country', 'interested_job', 'experience_years', 'qualification',
                  'salary_expectation', 'salary_currency', 'notes'] as $optional) {
            if (($data[$optional] ?? '') === '') {
                unset($data[$optional]);
            }
        }

        return $data;
    }

    private function normalise(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    // ---- file parsing / mapping suggestion --------------------------

    /**
     * @param array{tmp_name:string} $file
     * @return array{0:list<string>,1:list<array<string,string>>}
     */
    private function parse(array $file): array
    {
        $handle = fopen($file['tmp_name'], 'rb');
        if ($handle === false) {
            throw new ValidationException(['file' => ['The file could not be read.']]);
        }

        $maxRows = (int) $this->app->config()->get('import_export.leads.import.max_rows', 500);

        try {
            $first = fgetcsv($handle);
            if ($first === false) {
                throw new ValidationException(['file' => ['The file is empty.']]);
            }
            // Strip a UTF-8 BOM from the first header cell, if present.
            if (isset($first[0])) {
                $first[0] = preg_replace('/^\xEF\xBB\xBF/', '', $first[0]) ?? $first[0];
            }
            $headers = array_map(static fn ($h) => trim((string) $h), $first);

            $rows = [];
            while (($cells = fgetcsv($handle)) !== false) {
                if ($cells === [null] || $cells === false) {
                    continue; // blank line
                }
                if (count($rows) >= $maxRows) {
                    throw new ValidationException(['file' => ["This file has more than {$maxRows} rows. Split it and import in batches."]]);
                }
                $row = [];
                foreach ($cells as $i => $cell) {
                    $row[(string) $i] = (string) $cell;
                }
                $rows[] = $row;
            }

            return [$headers, $rows];
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string> $headers @return array<string,string> column index (as string) => field key */
    private function suggestMapping(array $headers): array
    {
        $mapping = [];
        $used = [];
        foreach ($headers as $i => $header) {
            $norm = $this->normalise($header);
            $field = null;
            if (isset(self::FIELDS[$norm])) {
                $field = $norm;
            } elseif (isset(self::SYNONYMS[$norm])) {
                $field = self::SYNONYMS[$norm];
            } else {
                foreach (self::FIELDS as $key => $label) {
                    if ($this->normalise(str_replace(['(', ')'], '', $key)) === $norm) {
                        $field = $key;
                        break;
                    }
                }
            }
            if ($field !== null && !isset($used[$field])) {
                $mapping[(string) $i] = $field;
                $used[$field] = true;
            }
        }

        return $mapping;
    }

    /** @param array{tmp_name:string} $file */
    private function store(array $file, string $relativeDir): string
    {
        $dir = $this->app->basePath($relativeDir);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $dest = $relativeDir . '/' . Ulid::generate() . '.csv';
        $absolute = $this->app->basePath($dest);

        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $absolute)
            : copy($file['tmp_name'], $absolute);

        if (!$moved) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }

        return $dest;
    }

    private function writeReport(ImportBatch $batch): string
    {
        $dest = 'storage/imports/' . $batch->publicId . '-report.csv';
        $handle = fopen($this->app->basePath($dest), 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not write the import report.');
        }

        try {
            Csv::writeRow($handle, ['Row', 'Status', 'Error']);
            foreach ($this->repo->problemRows($batch->id) as $row) {
                Csv::writeRow($handle, [(int) $row['row_number'], (string) $row['status'], (string) ($row['error'] ?? '')]);
            }
        } finally {
            fclose($handle);
        }

        return $dest;
    }
}
