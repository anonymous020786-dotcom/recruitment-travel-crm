<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\BranchScope;
use App\Auth\PermissionService;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\ReportRepository;
use App\Support\Csv;

/**
 * The report catalogue and its runner. A report is a title, a permission, the filters it takes, its column
 * headings and a streaming row source (ReportRepository). The same rows feed the screen (capped, with a
 * "truncated" notice), the print view and the CSV download (capped much higher, streamed, formula-injection
 * safe), so what you see is exactly what you export. Everything is scoped to the viewer's branches.
 */
final class ReportService
{
    /** Rows shown on screen; the CSV carries everything up to EXPORT_ROWS. */
    public const VIEW_ROWS = 500;
    public const EXPORT_ROWS = 50000;

    public const DEFAULT_RANGE_DAYS = 90;
    public const MAX_RANGE_DAYS = 1830;   // five years
    public const DEFAULT_EXPIRY_DAYS = 60;
    public const MAX_EXPIRY_DAYS = 365;

    /** @var array<string,array{title:string,group:string,description:string,permission:string,filter:string,columns:list<string>,numeric:list<int>}> */
    private const CATALOG = [
        'lead-sources' => [
            'title' => 'Lead sources', 'group' => 'Recruitment', 'permission' => 'leads.view', 'filter' => 'range',
            'description' => 'Where leads come from and how many convert into candidates (by the date the lead was created).',
            'columns' => ['Source', 'Leads', 'Converted', 'Conversion %'], 'numeric' => [1, 2, 3],
        ],
        'applications-by-employer' => [
            'title' => 'Applications by employer', 'group' => 'Recruitment', 'permission' => 'applications.view', 'filter' => 'range',
            'description' => 'For each employer, the applications made in the period and where they stand now.',
            'columns' => ['Employer', 'Applications', 'Live', 'Placed', 'Rejected', 'Cancelled'], 'numeric' => [1, 2, 3, 4, 5],
        ],
        'placements' => [
            'title' => 'Placements', 'group' => 'Recruitment', 'permission' => 'travel.view', 'filter' => 'range',
            'description' => 'Every candidate placed with an employer in the period.',
            'columns' => ['Placed on', 'Candidate', 'Candidate no.', 'Employer', 'Job', 'Country', 'Monthly salary', 'Currency', 'Contract ends', 'Status'], 'numeric' => [6],
        ],
        'expiring-documents' => [
            'title' => 'Expiring visas, medicals and passports', 'group' => 'Compliance', 'permission' => 'candidates.view', 'filter' => 'days',
            'description' => 'Approved visas, fit medical certificates and passports that expire soon. You only see the kinds you are allowed to view.',
            'columns' => ['Type', 'Candidate', 'Candidate no.', 'Reference', 'Expires', 'Days left'], 'numeric' => [5],
        ],
        'flights' => [
            'title' => 'Flights', 'group' => 'Travel', 'permission' => 'travel.view', 'filter' => 'range',
            'description' => 'Flights departing in the period, with ticket status.',
            'columns' => ['Departs', 'Candidate', 'Candidate no.', 'Application', 'Route', 'Airline', 'Flight', 'PNR', 'Status'], 'numeric' => [],
        ],
        'tour-packages' => [
            'title' => 'Tour bookings by package', 'group' => 'Tours', 'permission' => 'tours.bookings.view', 'filter' => 'range',
            'description' => 'Bookings created in the period, per package and currency, with the value of those going ahead.',
            'columns' => ['Package', 'Currency', 'Bookings', 'In pipeline', 'Confirmed / travelling', 'Completed', 'Cancelled', 'Revenue'], 'numeric' => [2, 3, 4, 5, 6, 7],
        ],
        'collections' => [
            'title' => 'Collections', 'group' => 'Finance', 'permission' => 'reports.finance.view', 'filter' => 'range',
            'description' => 'Money received per month, currency and payment method (reversed payments excluded; refunds are a separate report).',
            'columns' => ['Month', 'Currency', 'Method', 'Payments', 'Amount received'], 'numeric' => [3, 4],
        ],
        'payments-register' => [
            'title' => 'Payments register', 'group' => 'Finance', 'permission' => 'reports.finance.view', 'filter' => 'range',
            'description' => 'Every payment received in the period, with receipt number and how much has been applied to invoices.',
            'columns' => ['Received', 'Payment no.', 'Receipt no.', 'Customer', 'Method', 'Reference', 'Currency', 'Amount', 'Applied', 'Status'], 'numeric' => [7, 8],
        ],
        'invoices-register' => [
            'title' => 'Invoices register', 'group' => 'Finance', 'permission' => 'reports.finance.view', 'filter' => 'range',
            'description' => 'Every invoice raised in the period with what has been paid, refunded and is still owed.',
            'columns' => ['Invoice', 'Customer', 'For', 'Reference', 'Issued', 'Due', 'Currency', 'Total', 'Paid', 'Refunded', 'Outstanding', 'Status'], 'numeric' => [7, 8, 9, 10],
        ],
        'refunds-register' => [
            'title' => 'Refunds', 'group' => 'Finance', 'permission' => 'reports.finance.view', 'filter' => 'range',
            'description' => 'Refunds requested in the period, who asked, who approved, and where each stands.',
            'columns' => ['Requested', 'Refund no.', 'Customer', 'Payment', 'Invoice', 'Currency', 'Amount', 'Method', 'Status', 'Requested by', 'Approved by', 'Reason'], 'numeric' => [6],
        ],
        'overdue-invoices' => [
            'title' => 'Overdue invoices', 'group' => 'Finance', 'permission' => 'reports.finance.view', 'filter' => 'none',
            'description' => 'Every invoice past its due date that still owes money, oldest first — a live list, with the customer\'s phone for chasing.',
            'columns' => ['Invoice', 'Customer', 'Phone', 'Due', 'Days overdue', 'Currency', 'Outstanding', 'Status'], 'numeric' => [4, 6],
        ],
    ];

    public function __construct(
        private readonly ReportRepository $repo,
        private readonly PermissionService $permissions,
    ) {
    }

    /** @return array<string,list<array{key:string,title:string,description:string}>> group => reports the user may run */
    public function catalogFor(User $user): array
    {
        $out = [];
        foreach (self::CATALOG as $key => $def) {
            if ($this->permissions->userCan($user, $def['permission'])) {
                $out[$def['group']][] = ['key' => $key, 'title' => $def['title'], 'description' => $def['description']];
            }
        }

        return $out;
    }

    /** @return array{title:string,group:string,description:string,permission:string,filter:string,columns:list<string>,numeric:list<int>}|null null when unknown or not allowed */
    public function definition(string $key, User $user): ?array
    {
        $def = self::CATALOG[$key] ?? null;

        return $def !== null && $this->permissions->userCan($user, $def['permission']) ? $def : null;
    }

    /**
     * Normalise the filter inputs a report takes. A range defaults to the last 90 days; dates must be real,
     * in order, and not span more than five years. An expiry window is 1–365 days.
     *
     * @param array<string,mixed> $input
     * @return array{from:string,to:string,days:int}
     */
    public function filters(string $key, array $input): array
    {
        $today = gmdate('Y-m-d');
        $from = gmdate('Y-m-d', strtotime('-' . (self::DEFAULT_RANGE_DAYS - 1) . ' days'));
        $to = $today;
        $days = self::DEFAULT_EXPIRY_DAYS;

        if ((self::CATALOG[$key]['filter'] ?? '') === 'range') {
            $from = $this->date($input['from'] ?? null, 'from') ?? $from;
            $to = $this->date($input['to'] ?? null, 'to') ?? $to;
            if ($from > $to) {
                throw new ValidationException(['from' => ['The start date cannot be after the end date.']]);
            }
            if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_RANGE_DAYS) {
                throw new ValidationException(['to' => ['Choose a period of at most five years.']]);
            }
        } elseif ((self::CATALOG[$key]['filter'] ?? '') === 'days') {
            $raw = $input['days'] ?? null;
            if ($raw !== null && $raw !== '') {
                if (!is_numeric($raw) || (int) $raw < 1 || (int) $raw > self::MAX_EXPIRY_DAYS) {
                    throw new ValidationException(['days' => ['Choose between 1 and ' . self::MAX_EXPIRY_DAYS . ' days.']]);
                }
                $days = (int) $raw;
            }
        }

        return ['from' => $from, 'to' => $to, 'days' => $days];
    }

    /**
     * The report's rows, as display cells in column order. A generator: nothing is buffered.
     *
     * @param array{from:string,to:string,days:int} $filters
     * @return \Generator<int,list<string|int>>
     */
    public function rows(string $key, array $filters, BranchScope $scope, User $user): \Generator
    {
        $f = $filters;

        switch ($key) {
            case 'lead-sources':
                foreach ($this->repo->leadSources($scope, $f['from'], $f['to']) as $r) {
                    $n = (int) $r['leads'];
                    yield [$r['source'], $n, (int) $r['converted'], $n > 0 ? number_format((int) $r['converted'] / $n * 100, 1) : '0.0'];
                }
                break;
            case 'applications-by-employer':
                foreach ($this->repo->applicationsByEmployer($scope, $f['from'], $f['to']) as $r) {
                    yield [$r['employer'], (int) $r['applications'], (int) $r['live'], (int) $r['placed'], (int) $r['rejected'], (int) $r['cancelled']];
                }
                break;
            case 'placements':
                foreach ($this->repo->placements($scope, $f['from'], $f['to']) as $r) {
                    yield [
                        $r['placed_on'], $r['candidate'], $r['candidate_number'], $r['employer'], $r['job'], $r['country'],
                        $r['monthly_salary'] !== null ? number_format((float) $r['monthly_salary'], 2, '.', '') : '', (string) ($r['currency'] ?? ''),
                        (string) ($r['contract_end'] ?? ''), ucfirst((string) $r['status']),
                    ];
                }
                break;
            case 'expiring-documents':
                $today = gmdate('Y-m-d');
                $until = gmdate('Y-m-d', strtotime("+{$f['days']} days"));
                foreach ($this->repo->expiring($scope, $today, $until, $this->expiryKinds($user)) as $r) {
                    yield [$r['kind'], $r['candidate'], $r['candidate_number'], (string) $r['reference'], $r['expires'], (int) round((strtotime((string) $r['expires']) - strtotime($today)) / 86400)];
                }
                break;
            case 'flights':
                foreach ($this->repo->flights($scope, $f['from'], $f['to']) as $r) {
                    yield [
                        substr((string) $r['departure_at'], 0, 16), $r['candidate'], $r['candidate_number'], (string) ($r['application_number'] ?? ''),
                        $r['route'], (string) ($r['airline'] ?? ''), (string) ($r['flight_number'] ?? ''), (string) ($r['pnr'] ?? ''), ucfirst((string) $r['status']),
                    ];
                }
                break;
            case 'tour-packages':
                foreach ($this->repo->tourPackages($scope, $f['from'], $f['to']) as $r) {
                    yield [
                        $r['package'], $r['currency'], (int) $r['bookings'], (int) $r['open_pipeline'], (int) $r['confirmed'], (int) $r['completed'],
                        (int) $r['cancelled'], number_format((float) $r['revenue'], 2, '.', ''),
                    ];
                }
                break;
            case 'collections':
                foreach ($this->repo->collections($scope, $f['from'], $f['to']) as $r) {
                    yield [$r['month'], $r['currency'], ucwords(str_replace('_', ' ', (string) $r['method'])), (int) $r['payments'], $this->money($r['total'])];
                }
                break;
            case 'payments-register':
                foreach ($this->repo->payments($scope, $f['from'], $f['to']) as $r) {
                    yield [
                        substr((string) $r['paid_at'], 0, 16), $r['payment_number'], $r['receipt_number'], $r['customer'], ucwords(str_replace('_', ' ', (string) $r['method'])),
                        (string) ($r['reference'] ?? ''), $r['currency'], $this->money($r['amount']), $this->money($r['allocated']), ucfirst((string) $r['status']),
                    ];
                }
                break;
            case 'invoices-register':
                foreach ($this->repo->invoices($scope, $f['from'], $f['to']) as $r) {
                    yield [
                        $r['invoice_number'], $r['customer'], $r['kind'] === 'tour_booking' ? 'Tour booking' : ($r['kind'] === 'application' ? 'Recruitment' : 'Other'),
                        (string) ($r['reference'] ?? ''), (string) ($r['issued_on'] ?? ''), (string) ($r['due_on'] ?? ''), $r['currency'],
                        $this->money($r['grand_total']), $this->money($r['amount_paid']), $this->money($r['amount_refunded']), $this->money($r['outstanding']),
                        ucwords(str_replace('_', ' ', (string) $r['status'])),
                    ];
                }
                break;
            case 'refunds-register':
                foreach ($this->repo->refunds($scope, $f['from'], $f['to']) as $r) {
                    yield [
                        substr((string) $r['created_at'], 0, 16), $r['refund_number'], $r['customer'], $r['payment_number'], (string) ($r['invoice_number'] ?? 'credit'),
                        $r['currency'], $this->money($r['amount']), ucwords(str_replace('_', ' ', (string) $r['method'])), ucfirst((string) $r['status']),
                        (string) ($r['requested_by'] ?? ''), (string) ($r['approved_by'] ?? ''), (string) $r['reason'],
                    ];
                }
                break;
            case 'overdue-invoices':
                foreach ($this->repo->overdueInvoices($scope, gmdate('Y-m-d')) as $r) {
                    yield [
                        $r['invoice_number'], $r['customer'], (string) ($r['phone'] ?? ''), $r['due_on'], (int) $r['days_overdue'], $r['currency'],
                        $this->money($r['outstanding']), ucwords(str_replace('_', ' ', (string) $r['status'])),
                    ];
                }
                break;
            default:
                throw new \InvalidArgumentException("Unknown report: {$key}");
        }
    }

    /**
     * The first `$limit` rows for the screen, and whether there were more.
     *
     * @param array{from:string,to:string,days:int} $filters
     * @return array{rows:list<list<string|int>>,truncated:bool}
     */
    public function page(string $key, array $filters, BranchScope $scope, User $user, int $limit = self::VIEW_ROWS): array
    {
        $rows = [];
        foreach ($this->rows($key, $filters, $scope, $user) as $row) {
            if (count($rows) >= $limit) {
                return ['rows' => $rows, 'truncated' => true];
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'truncated' => false];
    }

    /**
     * Write the report as CSV (header + rows) to `$handle`, streaming, and return the number of data rows.
     * Cells are neutralised against spreadsheet formula injection by Csv::writeRow.
     *
     * @param resource $handle
     * @param array{from:string,to:string,days:int} $filters
     */
    public function exportCsv(string $key, array $filters, BranchScope $scope, User $user, $handle, int $cap = self::EXPORT_ROWS): int
    {
        $def = self::CATALOG[$key] ?? throw new \InvalidArgumentException("Unknown report: {$key}");
        Csv::writeRow($handle, $def['columns']);

        $count = 0;
        foreach ($this->rows($key, $filters, $scope, $user) as $row) {
            if ($count >= $cap) {
                break;
            }
            Csv::writeRow($handle, $row);
            $count++;
        }

        return $count;
    }

    // ---- internals -------------------------------------------------

    /** @return list<string> the document kinds this user is allowed to see */
    private function expiryKinds(User $user): array
    {
        $kinds = [];
        foreach (['visa' => 'visa.view', 'medical' => 'medical.view', 'passport' => 'candidates.view'] as $kind => $permission) {
            if ($this->permissions->userCan($user, $permission)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    private function money(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function date(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new ValidationException([$field => ['Use a valid date.']]);
        }

        return $d->format('Y-m-d');
    }
}
