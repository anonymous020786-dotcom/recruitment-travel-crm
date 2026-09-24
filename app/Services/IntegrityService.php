<?php

declare(strict_types=1);

namespace App\Services;

use App\Notifications\NotificationService;
use App\Repositories\IntegrityRepository;
use App\Repositories\UserRepository;
use App\Support\Db;

/**
 * Runs every ledger / pipeline consistency probe (IntegrityRepository), keeps the latest result in `settings`
 * (`integrity:last`, read by the admin screen) and, when something is wrong, tells the administrators once per
 * check per day. Finding a problem is the job working — it is reported, never thrown.
 */
final class IntegrityService
{
    public const SETTINGS_KEY = 'integrity:last';
    private const ADMIN_ROLE = 'super_admin';

    /** check code => [title, repository method] */
    public const CHECKS = [
        'invoice_paid'        => ['Invoice amount paid matches its payments', 'invoicePaidMismatch'],
        'invoice_refunded'    => ['Invoice amount refunded matches paid refunds', 'invoiceRefundedMismatch'],
        'invoice_status'      => ['Invoice status matches its money', 'invoiceStatusMismatch'],
        'invoice_totals'      => ['Invoice totals match their lines', 'invoiceTotalsMismatch'],
        'payment_allocations' => ['No payment is over-allocated', 'paymentOverAllocated'],
        'payment_refunds'     => ['Refunds never exceed their payment', 'refundsExceedPayment'],
        'payment_receipts'    => ['Every payment has its receipt', 'paymentReceiptMismatch'],
        'invoice_targets'     => ['Every invoice points at a real application or booking', 'orphanInvoiceables'],
        'application_history' => ['Application status matches its history', 'applicationHistoryMismatch'],
        'sequences'           => ['Document counters are ahead of issued numbers', 'sequencesBehind'],
    ];

    public function __construct(
        private readonly Db $db,
        private readonly IntegrityRepository $repo,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @return array{at:string,checked:int,failed:int,findings:list<array{check:string,title:string,ref:string,detail:string}>}
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $findings = [];
        $failedChecks = [];

        foreach (self::CHECKS as $code => [$title, $method]) {
            foreach ($this->repo->{$method}() as $row) {
                $findings[] = ['check' => $code, 'title' => $title, 'ref' => $row['ref'], 'detail' => $row['detail']];
                $failedChecks[$code] = ($failedChecks[$code] ?? 0) + 1;
            }
        }

        $result = ['at' => $now->format('Y-m-d H:i:s'), 'checked' => count(self::CHECKS), 'failed' => count($failedChecks), 'findings' => $findings];
        $this->db->affectingStatement(
            'INSERT INTO settings (key_name, value, is_public) VALUES (:k, :v, 0) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['k' => self::SETTINGS_KEY, 'v' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

        $this->alert($failedChecks, $findings, $now->format('Y-m-d'));

        return $result;
    }

    /** @return array{at:string,checked:int,failed:int,findings:list<array<string,string>>}|null the last stored result */
    public function last(): ?array
    {
        $raw = $this->db->selectValue('SELECT value FROM settings WHERE key_name = :k', ['k' => self::SETTINGS_KEY]);
        $data = $raw !== null ? json_decode((string) $raw, true) : null;

        return is_array($data) ? $data : null;
    }

    // ---- internals -------------------------------------------------

    /**
     * @param array<string,int> $failedChecks check code => number of offending rows
     * @param list<array{check:string,title:string,ref:string,detail:string}> $findings
     */
    private function alert(array $failedChecks, array $findings, string $date): void
    {
        if ($failedChecks === []) {
            return;
        }
        $admins = $this->users->activeIdsByRole(self::ADMIN_ROLE);

        foreach ($failedChecks as $code => $n) {
            $first = null;
            foreach ($findings as $f) {
                if ($f['check'] === $code) {
                    $first = $f;
                    break;
                }
            }
            foreach ($admins as $adminId) {
                $this->notifications->notify(
                    userId: $adminId,
                    type: 'integrity_alert',
                    title: 'Data check failed: ' . self::CHECKS[$code][0],
                    body: "{$n} record" . ($n === 1 ? '' : 's') . ' affected, e.g. ' . ($first['ref'] ?? '?') . ' — ' . ($first['detail'] ?? ''),
                    dedupeKey: "integrity:{$code}:{$date}:u{$adminId}",
                );
            }
        }
    }
}
