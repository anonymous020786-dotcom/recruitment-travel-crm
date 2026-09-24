<?php

declare(strict_types=1);

/**
 * Performance audit battery: runs the hot read paths (list screens, dashboard, reports, integrity probes,
 * reminder scans, notifications) as a branch-scoped manager would, and prints the wall time of each.
 * Run it against a database loaded with realistic volume (see scripts/perf-volume.sql) — never production:
 *
 *   DB_NAME=crm_explain php scripts/perf-audit.php [--budget-ms=300]
 *
 * Exit code 1 if anything exceeds the budget (default 300 ms; the integrity probes and the dashboard are
 * given 5x — they run from cron / are cached). Pair it with the MariaDB slow log (long_query_time=0,
 * log_output=TABLE) to see rows_examined per statement.
 */

use App\Auth\BranchScope;
use App\Repositories\ApplicationRepository;
use App\Repositories\CandidateRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\LeadRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\UserRepository;
use App\Services\DashboardService;
use App\Services\IntegrityService;
use App\Services\PaymentReminderService;
use App\Services\ReportService;
use App\Support\Application;
use App\Support\Db;
use App\Support\ListQuery;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$opts = getopt('', ['budget-ms::']);
$budget = (int) ($opts['budget-ms'] ?? 300);
$db = $app->get(Db::class);

$branchId = (int) $db->selectValue("SELECT id FROM branches WHERE code = 'VOL-A'");
if ($branchId === 0) {
    fwrite(STDERR, "No VOL-A branch: load scripts/perf-volume.sql into a scratch database first.\n");
    exit(2);
}
$scope = BranchScope::of([$branchId]);
$user = $app->get(UserRepository::class)->findById((int) $db->selectValue("SELECT MIN(id) FROM users WHERE email LIKE 'vol%@dev.local'"));

$q = static fn (array $search = [], array $filters = [], string $sort = '', int $page = 1): ListQuery
    => ListQuery::of(['search' => $search['q'] ?? '', 'filters' => $filters, 'sort' => $sort, 'page' => $page]);

/** @var list<array{0:string,1:callable,2:int}> $cases label, work, budget multiplier */
$cases = [
    ['leads: first page',                 fn () => $app->get(LeadRepository::class)->paginate($q(), $scope), 1],
    ['leads: deep page (500)',            fn () => $app->get(LeadRepository::class)->paginate($q([], [], '', 500), $scope), 1],
    ['leads: by name',                    fn () => $app->get(LeadRepository::class)->paginate($q(['q' => 'Lead 4242']), $scope), 1],
    ['leads: by phone prefix',            fn () => $app->get(LeadRepository::class)->paginate($q(['q' => '800004']), $scope), 1],
    ['leads: by status',                  fn () => $app->get(LeadRepository::class)->paginate($q([], ['status' => '2']), $scope), 1],
    ['candidates: first page',            fn () => $app->get(CandidateRepository::class)->paginate($q(), $scope), 1],
    ['candidates: by name',               fn () => $app->get(CandidateRepository::class)->paginate($q(['q' => 'Person Aslam 1234']), $scope), 1],
    ['applications: first page',          fn () => $app->get(ApplicationRepository::class)->paginate($q(), $scope), 1],
    ['applications: by status',           fn () => $app->get(ApplicationRepository::class)->paginate($q([], ['status' => 'shortlisted']), $scope), 1],
    ['invoices: first page',              fn () => $app->get(InvoiceRepository::class)->paginate($q(), $scope), 1],
    ['invoices: overdue filter',          fn () => $app->get(InvoiceRepository::class)->paginate($q([], ['due' => 'overdue']), $scope), 1],
    ['invoices: search',                  fn () => $app->get(InvoiceRepository::class)->paginate($q(['q' => 'INV-2026-000777']), $scope), 1],
    ['invoices: aging',                   fn () => $app->get(InvoiceRepository::class)->aging($scope), 1],
    ['invoices: top debtors',             fn () => $app->get(InvoiceRepository::class)->topDebtors($scope, 5), 1],
    ['invoices: summary',          fn () => $app->get(InvoiceRepository::class)->summary($scope), 1],
    ['invoices: overdue reminder scan',   fn () => $app->get(InvoiceRepository::class)->overdueForReminder(gmdate('Y-m-d')), 1],
    ['payments: first page',              fn () => $app->get(PaymentRepository::class)->paginate($q(), $scope), 1],
    ['notifications: unread count',       fn () => $app->get(NotificationRepository::class)->unreadCount($user->id), 1],
    ['notifications: recent',             fn () => $app->get(NotificationRepository::class)->recent($user->id), 1],
    ['dashboard: cold snapshot',          fn () => $app->get(DashboardService::class)->snapshot($user, $scope, new DateTimeImmutable('now', new DateTimeZone('UTC')), true), 5],
    ['integrity: all probes',             fn () => $app->get(IntegrityService::class)->run(new DateTimeImmutable('now', new DateTimeZone('UTC'))), 5],
];

foreach (['collections', 'payments-register', 'invoices-register', 'overdue-invoices', 'lead-sources', 'applications-by-employer', 'placements'] as $report) {
    $cases[] = ["report: {$report}", function () use ($app, $report, $scope, $user): int {
        $svc = $app->get(ReportService::class);
        $filters = $svc->filters($report, ['from' => gmdate('Y-m-d', strtotime('-400 days')), 'to' => gmdate('Y-m-d')]);

        return count($svc->page($report, $filters, $scope, $user)['rows']);
    }, 2];
}

printf("%-38s %9s  %s\n", 'operation', 'ms', '');
$failed = 0;
foreach ($cases as [$label, $work, $mult]) {
    $work(); // warm the buffer pool and any lazy container wiring
    $t = hrtime(true);
    $work();
    $ms = (hrtime(true) - $t) / 1e6;
    $limit = $budget * $mult;
    $flag = $ms > $limit ? 'SLOW (> ' . $limit . ')' : '';
    $failed += $flag !== '' ? 1 : 0;
    printf("%-38s %9.1f  %s\n", $label, $ms, $flag);
}

echo $failed === 0 ? "\nAll within budget.\n" : "\n{$failed} over budget.\n";
exit($failed === 0 ? 0 : 1);
