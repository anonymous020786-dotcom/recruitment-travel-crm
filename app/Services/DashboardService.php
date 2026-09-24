<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\BranchScope;
use App\Auth\PermissionService;
use App\Domain\StatusMachine;
use App\Models\User;
use App\Repositories\DashboardRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\LeadRepository;
use App\Support\Db;

/**
 * Builds the dashboard snapshot for one viewer. Only the widget groups the viewer's permissions allow are
 * computed (so a counselor never triggers a finance query), every figure is scoped to the viewer's branches,
 * and the finished snapshot is cached for a short time in `settings`, keyed by (branch scope, allowed groups) —
 * never by user — so people looking at the same slice of the business share one computation.
 * Nothing in a snapshot is personal; the follow-up queue is fetched live by the controller.
 */
final class DashboardService
{
    public const CACHE_SECONDS = 60;
    private const TREND_MONTHS = 6;
    private const EXPIRY_WINDOW_DAYS = 30;

    public function __construct(
        private readonly Db $db,
        private readonly DashboardRepository $repo,
        private readonly LeadRepository $leads,
        private readonly InvoiceRepository $invoices,
        private readonly PermissionService $permissions,
        private readonly StatusMachine $statuses,
        private readonly int $cacheSeconds = self::CACHE_SECONDS,
    ) {
    }

    /**
     * Pre-compute the snapshots people are about to ask for (cron/dashboard-cache.php): one per distinct
     * (branch scope, widget set) among the given users, always freshly built. With caching off there is nothing to warm.
     *
     * @param iterable<array{0:User,1:BranchScope}> $viewers
     * @return int snapshots built
     */
    public function warm(iterable $viewers, ?\DateTimeImmutable $now = null): int
    {
        if ($this->cacheSeconds <= 0) {
            return 0;
        }
        $seen = [];
        foreach ($viewers as [$user, $scope]) {
            $key = $this->cacheKey($user, $scope);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->snapshot($user, $scope, $now, refresh: true);
        }

        return count($seen);
    }

    /** @return array<string,mixed> */
    public function snapshot(User $user, BranchScope $scope, ?\DateTimeImmutable $now = null, bool $refresh = false): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $groups = $this->groupsFor($user);
        $key = $this->cacheKey($user, $scope);

        if (!$refresh && $this->cacheSeconds > 0 && ($hit = $this->cached($key, $now)) !== null) {
            return $hit;
        }

        $data = $this->build($groups, $scope, $now);
        if ($this->cacheSeconds > 0) {
            $this->store($key, $data, $now);
        }

        return $data;
    }

    /** @return list<string> the widget groups this user may see */
    public function groupsFor(User $user): array
    {
        $map = [
            'leads'        => 'leads.view',
            'candidates'   => 'candidates.view',
            'pipeline'     => 'applications.view',
            'interviews'   => 'interviews.view',
            'visa'         => 'visa.view',
            'medical'      => 'medical.view',
            'travel'       => 'travel.view',
            'tours'        => 'tours.bookings.view',
            'finance'      => 'invoices.view',
            'refunds'      => 'refunds.approve',
        ];
        $groups = [];
        foreach ($map as $group => $permission) {
            if ($this->permissions->userCan($user, $permission)) {
                $groups[] = $group;
            }
        }
        if ($groups !== [] && $this->permissions->userCan($user, 'candidates.view')) {
            $groups[] = 'passports';
        }
        sort($groups);

        return $groups;
    }

    // ---- internals -------------------------------------------------

    private function cacheKey(User $user, BranchScope $scope): string
    {
        return 'dash:' . sha1(json_encode([$scope->orgWide, $scope->ids, $this->groupsFor($user)]) ?: '');
    }

    /**
     * @param list<string> $groups
     * @return array<string,mixed>
     */
    private function build(array $groups, BranchScope $scope, \DateTimeImmutable $now): array
    {
        $has = static fn (string $g): bool => in_array($g, $groups, true);
        $today = $now->format('Y-m-d');
        $month = $now->format('Y-m');
        $months = $this->lastMonths($now);
        $until = $now->modify('+' . self::EXPIRY_WINDOW_DAYS . ' days')->format('Y-m-d');

        $data = ['generated_at' => $now->format('Y-m-d H:i:s'), 'groups' => $groups, 'months' => $months, 'attention' => []];

        if ($has('leads')) {
            $byStatus = $this->leads->statusCounts($scope);
            $data['leads'] = [
                'by_status' => $byStatus,
                'open'      => array_sum(array_diff_key($byStatus, array_flip(['converted', 'lost', 'not_interested']))),
                'monthly'   => $this->series($this->repo->monthly('leads', 'created_at', 'branch_id', $scope, 'deleted_at IS NULL'), $months),
            ];
        }
        if ($has('candidates')) {
            $monthly = $this->repo->monthly('candidates', 'created_at', 'branch_id', $scope, 'deleted_at IS NULL');
            $data['candidates'] = ['total' => array_sum($monthly), 'new_month' => $monthly[$month] ?? 0, 'monthly' => $this->series($monthly, $months)];
        }
        if ($has('pipeline') || $has('travel')) {
            $apps = $this->repo->applicationsByStatus($scope);
            if ($has('pipeline')) {
                $order = $this->statuses->states('application');
                $ordered = [];
                foreach ($order as $s) {
                    if (isset($apps[$s])) {
                        $ordered[$s] = $apps[$s];
                    }
                }
                $data['pipeline'] = ['by_status' => $ordered, 'live' => array_sum(array_diff_key($apps, array_flip(['placed', 'rejected', 'cancelled'])))];
            }
            if ($has('travel')) {
                $stages = ['visa_approved', 'ticket_pending', 'ticket_booked', 'departed'];
                $data['travel'] = ['stages' => array_combine($stages, array_map(static fn (string $s): int => $apps[$s] ?? 0, $stages)), 'placed' => $apps['placed'] ?? 0];
                $placements = $this->repo->monthly('placements', 'placed_on', 'branch_id', $scope);
                $data['travel']['placed_month'] = $placements[$month] ?? 0;
                $data['travel']['monthly'] = $this->series($placements, $months);
            }
        }
        if ($has('interviews')) {
            $data['interviews'] = [
                'today' => $this->repo->interviewsBetween($scope, $today, $today),
                'next7' => $this->repo->interviewsBetween($scope, $today, $now->modify('+7 days')->format('Y-m-d')),
            ];
        }
        if ($has('visa')) {
            $data['visa'] = $this->repo->visas($scope, $today, $until);
        }
        if ($has('medical')) {
            $data['medical'] = ['expiring' => $this->repo->medicalExpiring($scope, $today, $until)];
        }
        if ($has('passports')) {
            $data['passports'] = ['expiring' => $this->repo->passportsExpiring($scope, $today, $until)];
        }
        if ($has('tours')) {
            $data['tours'] = $this->repo->tours($scope, $today, $now->modify('+30 days')->format('Y-m-d'));
        }
        if ($has('finance')) {
            $data['finance'] = [
                'summary'   => $this->invoices->summary($scope),
                'collected' => $this->repo->collectedSince($scope, $now->modify('-30 days')->format('Y-m-d H:i:s')),
            ];
        }
        if ($has('refunds')) {
            $data['refunds'] = ['pending' => $this->repo->refundsPending($scope)];
        }

        $data['attention'] = $this->attention($data);

        return $data;
    }

    /**
     * The "needs attention" list: only non-zero items, each with where to go.
     *
     * @param array<string,mixed> $d
     * @return list<array{label:string,count:int,href:string,tone:string}>
     */
    private function attention(array $d): array
    {
        $items = [
            ['Interviews today', (int) ($d['interviews']['today'] ?? 0), '/interviews', 'blue'],
            ['Visas expiring within ' . self::EXPIRY_WINDOW_DAYS . ' days', (int) ($d['visa']['expiring'] ?? 0), '/visa?expiry=expiring', 'amber'],
            ['Medical certificates expiring within ' . self::EXPIRY_WINDOW_DAYS . ' days', (int) ($d['medical']['expiring'] ?? 0), '/medical?expiry=expiring', 'amber'],
            ['Passports expiring within ' . self::EXPIRY_WINDOW_DAYS . ' days', (int) ($d['passports']['expiring'] ?? 0), '/candidates', 'amber'],
            ['Candidates awaiting a ticket', (int) ($d['travel']['stages']['ticket_pending'] ?? 0) + (int) ($d['travel']['stages']['visa_approved'] ?? 0), '/travel', 'blue'],
            ['Tours starting in the next 30 days', (int) ($d['tours']['upcoming'] ?? 0), '/tours/bookings?when=upcoming', 'blue'],
            ['Refunds waiting for approval', (int) ($d['refunds']['pending'] ?? 0), '/refunds?status=pending', 'rose'],
        ];
        foreach ($d['finance']['summary'] ?? [] as $s) {
            if ((float) $s['overdue'] > 0) {
                $items[] = ['Overdue invoices (' . $s['currency'] . ' ' . number_format((float) $s['overdue'], 2) . ')', 1, '/invoices?due=overdue', 'rose'];
            }
        }

        $out = [];
        foreach ($items as [$label, $count, $href, $tone]) {
            if ($count > 0) {
                $out[] = ['label' => $label, 'count' => $count, 'href' => $href, 'tone' => $tone];
            }
        }

        return $out;
    }

    /** @return list<string> the current month and the previous five, oldest first (YYYY-MM) */
    private function lastMonths(\DateTimeImmutable $now): array
    {
        $first = $now->modify('first day of this month');
        $months = [];
        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $months[] = $first->modify("-{$i} months")->format('Y-m');
        }

        return $months;
    }

    /**
     * @param array<string,int> $monthly
     * @param list<string> $months
     * @return list<int> one value per month, zero where there were no rows
     */
    private function series(array $monthly, array $months): array
    {
        return array_map(static fn (string $m): int => $monthly[$m] ?? 0, $months);
    }

    /** @return array<string,mixed>|null */
    private function cached(string $key, \DateTimeImmutable $now): ?array
    {
        $raw = $this->db->selectValue('SELECT value FROM settings WHERE key_name = :k', ['k' => $key]);
        if ($raw === null) {
            return null;
        }
        $row = json_decode((string) $raw, true);
        if (!is_array($row) || !isset($row['at'], $row['data']) || $now->getTimestamp() - (int) $row['at'] > $this->cacheSeconds) {
            return null;
        }

        return (array) $row['data'] + ['from_cache' => true];
    }

    /** @param array<string,mixed> $data */
    private function store(string $key, array $data, \DateTimeImmutable $now): void
    {
        $this->db->affectingStatement(
            'INSERT INTO settings (key_name, value, is_public) VALUES (:k, :v, 0) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['k' => $key, 'v' => json_encode(['at' => $now->getTimestamp(), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
    }
}
