<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Notifications\NotificationService;
use App\Repositories\MedicalRepository;
use App\Repositories\PassportRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisaHistoryRepository;
use App\Repositories\VisaRepository;
use App\Support\Db;

/**
 * The cron-driven expiry sweeps for visas, medical certificates and passports.
 *
 * Reminders fire once per *window* (config `cron.reminder_windows.*`): a record
 * is put in the smallest window that still contains it, and the notification's
 * dedupe key carries that window, so re-runs — or a missed day — never double
 * up, and a record first seen 25 days out gets the "30 days" reminder only
 * (not 180 and 90 as well). Already-expired records use a final "expired"
 * bucket. Recipients are the record's owner plus, for visas and medicals, the
 * visa team of the candidate's branch.
 */
final class ExpiryService
{
    /** Role whose members handle visas and medicals. */
    private const VISA_TEAM_ROLE = 'visa';

    /**
     * @param array<string,list<int>> $windows days-before-expiry windows keyed by kind (visa, medical, passport)
     */
    public function __construct(
        private readonly Db $db,
        private readonly VisaRepository $visas,
        private readonly VisaHistoryRepository $visaHistory,
        private readonly MedicalRepository $medical,
        private readonly PassportRepository $passports,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly array $windows,
    ) {
    }

    /** @return int notifications attempted (deduped ones are silently dropped by the unique key) */
    public function remindVisas(string $today): int
    {
        $windows = $this->windowsFor('visa');
        $sent = 0;

        foreach ($this->visas->dueForReminder(max($windows), $today) as $r) {
            $days = $this->daysUntil((string) $r['expiry_date'], $today);
            $bucket = $this->bucket($windows, $days);
            $recipients = $this->recipients($r['owner_id'] ?? null, (int) $r['branch_id'], true);
            $sent += $this->fanOut(
                $recipients,
                'visa_expiring',
                "Visa {$this->when($days)}: {$r['candidate_name']}",
                "{$r['country']} visa · expiry {$r['expiry_date']}",
                'visa',
                (int) $r['id'],
                "expiry:visa:{$r['id']}:{$bucket}",
            );
        }

        return $sent;
    }

    /** @return int notifications attempted */
    public function remindMedical(string $today): int
    {
        $windows = $this->windowsFor('medical');
        $sent = 0;

        foreach ($this->medical->dueForReminder(max($windows), $today) as $r) {
            $days = $this->daysUntil((string) $r['expires_at'], $today);
            $bucket = $this->bucket($windows, $days);
            $recipients = $this->recipients($r['owner_id'] ?? null, (int) $r['branch_id'], true);
            $sent += $this->fanOut(
                $recipients,
                'medical_expiring',
                "Medical certificate {$this->when($days)}: {$r['candidate_name']}",
                "Certificate expires {$r['expires_at']}.",
                'candidate',
                (int) $r['candidate_id'],
                "expiry:medical:{$r['id']}:{$bucket}",
                'medical',
            );
        }

        return $sent;
    }

    /** @return int notifications attempted */
    public function remindPassports(string $today): int
    {
        $windows = $this->windowsFor('passport');
        $sent = 0;

        foreach ($this->passports->dueForReminder(max($windows), $today) as $r) {
            $days = $this->daysUntil((string) $r['expiry_date'], $today);
            $bucket = $this->bucket($windows, $days);
            $sent += $this->fanOut(
                $this->recipients($r['owner_id'] ?? null, 0, false),
                'passport_expiring',
                "Passport {$this->when($days)}: {$r['candidate_name']}",
                "Passport {$r['passport_number']} · expiry {$r['expiry_date']}",
                'candidate',
                (int) $r['candidate_id'],
                "expiry:passport:{$r['id']}:{$bucket}",
                'passports',
            );
        }

        return $sent;
    }

    /**
     * Approved visas whose expiry date has passed become `expired`, written by
     * the system (NULL actor) with a history row and an audit entry. A row that
     * changed between the read and the write is skipped and caught next run.
     *
     * @return int visas expired
     */
    public function expireVisas(string $today): int
    {
        $expired = 0;

        foreach ($this->visas->lapsed($today) as $v) {
            $done = $this->db->transaction(function () use ($v): bool {
                if ($this->visas->markExpired($v['id'], $v['record_version']) === 0) {
                    return false;
                }
                $this->visaHistory->append($v['id'], 'approved', 'expired', false, 'Expired automatically: the visa expiry date has passed', null);
                $this->audit->log('status_changed', 'visa', 'visa_application', $v['id'], ['status' => 'approved'], ['status' => 'expired'], 'expiry sweep');

                return true;
            });
            $expired += $done ? 1 : 0;
        }

        return $expired;
    }

    // ---- internals -------------------------------------------------

    /** @return non-empty-list<int> ascending */
    private function windowsFor(string $kind): array
    {
        $w = array_values(array_unique(array_map('intval', $this->windows[$kind] ?? [30])));
        sort($w);

        return $w === [] ? [30] : $w;
    }

    private function daysUntil(string $date, string $today): int
    {
        return (int) round((strtotime($date . ' UTC') - strtotime($today . ' UTC')) / 86400);
    }

    /**
     * The smallest window that still contains the record, or -1 once it has expired.
     *
     * @param non-empty-list<int> $windows ascending
     */
    private function bucket(array $windows, int $days): int
    {
        if ($days < 0) {
            return -1;
        }
        foreach ($windows as $w) {
            if ($days <= $w) {
                return $w;
            }
        }

        return end($windows);
    }

    private function when(int $days): string
    {
        return match (true) {
            $days < 0   => 'expired ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago',
            $days === 0 => 'expires today',
            default     => "expires in {$days} day" . ($days === 1 ? '' : 's'),
        };
    }

    /** @return list<int> the owner, plus the branch's visa team when asked */
    private function recipients(mixed $ownerId, int $branchId, bool $withVisaTeam): array
    {
        $ids = [];
        if ($ownerId !== null && (int) $ownerId > 0) {
            $ids[] = (int) $ownerId;
        }
        if ($withVisaTeam && $branchId > 0) {
            array_push($ids, ...$this->users->activeIdsByRoleInBranch(self::VISA_TEAM_ROLE, $branchId));
        }

        return array_values(array_unique($ids));
    }

    /**
     * One notification per recipient. The dedupe key is global in the table, so it carries the user id.
     *
     * @param list<int> $recipients
     */
    private function fanOut(array $recipients, string $type, string $title, string $body, string $linkType, int $linkId, string $dedupe, ?string $fragment = null): int
    {
        foreach ($recipients as $userId) {
            $this->notifications->notify($userId, $type, $title, $body, $linkType, $linkId, $fragment, "{$dedupe}:u{$userId}");
        }

        return count($recipients);
    }
}
