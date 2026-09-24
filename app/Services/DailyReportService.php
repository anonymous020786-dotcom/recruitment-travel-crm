<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\BranchScope;
use App\Mail\MailComposer;
use App\Repositories\DailyReportRepository;
use App\Repositories\UserRepository;

/**
 * The morning digest of yesterday (cron/daily-report.php). One report per branch — emailed to that branch's
 * managers — plus an organisation-wide one for the administrators. Each (day, audience) is **claimed** in
 * `settings` before anything is sent, so re-running the job, or running it twice, can never email the same
 * digest twice. A day on which nothing happened is stored but not emailed (a "quiet day" mail is just noise).
 */
final class DailyReportService
{
    private const ADMIN_ROLE = 'super_admin';
    private const MANAGER_ROLE = 'manager';

    public function __construct(
        private readonly DailyReportRepository $repo,
        private readonly UserRepository $users,
        private readonly MailComposer $mail,
        private readonly bool $email = true,
    ) {
    }

    /**
     * @return array{date:string,reports:int,emails:int}
     */
    public function run(string $date): array
    {
        $reports = 0;
        $emails = 0;

        $audiences = [['key' => 'all', 'label' => 'All branches', 'scope' => BranchScope::orgWide(), 'to' => $this->users->recipientsByRole(self::ADMIN_ROLE)]];
        $adminIds = array_column($audiences[0]['to'], 'id');
        foreach ($this->repo->activeBranches() as $b) {
            $managers = array_values(array_filter(
                $this->users->recipientsByRole(self::MANAGER_ROLE, $b['id']),
                static fn (array $u): bool => !in_array($u['id'], $adminIds, true),   // an administrator already gets the organisation-wide one
            ));
            $audiences[] = ['key' => (string) $b['id'], 'label' => $b['name'], 'scope' => BranchScope::of([$b['id']]), 'to' => $managers];
        }

        foreach ($audiences as $a) {
            $data = $this->build($date, $a['scope']);
            $data['audience'] = $a['label'];
            if (!$this->repo->claim("daily-report:{$date}:{$a['key']}", (string) json_encode($data, JSON_UNESCAPED_UNICODE))) {
                continue; // already produced (and emailed) for this day
            }
            $reports++;

            if ($this->email && !$data['quiet']) {
                foreach ($a['to'] as $u) {
                    if ($this->mail->send($u['email'], 'daily-report', $data + ['name' => $u['name'], 'subject' => "Daily report {$date} — {$a['label']}"])) {
                        $emails++;
                    }
                }
            }
        }

        return ['date' => $date, 'reports' => $reports, 'emails' => $emails];
    }

    /**
     * @return array{date:string,counts:array<string,int>,money:array<string,array<string,array{count:int,total:string}>>,quiet:bool}
     */
    public function build(string $date, BranchScope $scope): array
    {
        $counts = [
            'New leads'            => $this->repo->countOn('leads', 'created_at', 'branch_id', $scope, $date, 'deleted_at IS NULL'),
            'New candidates'       => $this->repo->countOn('candidates', 'created_at', 'branch_id', $scope, $date, 'deleted_at IS NULL'),
            'New applications'     => $this->repo->countOn('applications', 'applied_at', 'branch_id', $scope, $date),
            'Interviews held'      => $this->repo->interviewsHeld($scope, $date),
            'Placements'           => $this->repo->countOn('placements', 'placed_on', 'branch_id', $scope, $date),
            'New tour bookings'    => $this->repo->countOn('tour_bookings', 'created_at', 'branch_id', $scope, $date),
        ];
        $money = [
            'Invoices issued'  => $this->repo->moneyOn('invoices', 'grand_total', 'issued_on', 'branch_id', $scope, $date, "status <> 'void'"),
            'Payments received' => $this->repo->moneyOn('payments', 'amount', 'paid_at', 'branch_id', $scope, $date, "status = 'recorded'"),
            'Refunds paid out' => $this->repo->moneyOn('refunds', 'amount', 'refunded_at', 'branch_id', $scope, $date, "status = 'paid'"),
        ];

        $quiet = array_sum($counts) === 0 && array_filter($money) === [];

        return ['date' => $date, 'counts' => $counts, 'money' => $money, 'quiet' => $quiet];
    }
}
