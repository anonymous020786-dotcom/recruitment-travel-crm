<?php

declare(strict_types=1);

namespace App\Services;

use App\Notifications\NotificationService;
use App\Repositories\InvoiceRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Support\Money;
use App\Support\Ulid;

/**
 * Overdue-invoice reminders (cron/payment-reminders.php). Every issued or partly-paid invoice past its
 * due date that still owes money is reported to the person who raised it and to the accounts team of its
 * branch — then again each further week (bucket = whole weeks overdue, capped), never more often, so the
 * job can run as often as you like without repeating itself. The dedupe key carries the invoice, the
 * bucket and the recipient (keys are global in `notifications`).
 *
 * Each overdue invoice also gets one follow-up task ("Collect payment"), due today, assigned to the branch's
 * first accounts user (else whoever raised the invoice). While that task is pending no second one is made; if
 * it is completed and the invoice is still overdue a month on, a fresh one is opened (bucket = 28-day span).
 */
final class PaymentReminderService
{
    private const ACCOUNTS_ROLE = 'accounts';

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly TaskRepository $tasks,
        private readonly int $maxRepeats = 8,
    ) {
    }

    /** @return int notifications attempted (already-sent ones are dropped by the unique key) */
    public function remindOverdue(string $today): int
    {
        $sent = 0;

        foreach ($this->invoices->overdueForReminder($today) as $inv) {
            $days = (int) round((strtotime($today . ' UTC') - strtotime($inv['due_on'] . ' UTC')) / 86400);
            $bucket = min(intdiv(max($days, 1) - 1, 7), $this->maxRepeats);

            $recipients = $this->users->activeIdsByRoleInBranch(self::ACCOUNTS_ROLE, $inv['branch_id']);
            if ($inv['created_by'] !== null) {
                $recipients[] = $inv['created_by'];
            }

            foreach (array_values(array_unique($recipients)) as $userId) {
                $this->notifications->notify(
                    userId: $userId,
                    type: 'invoice_overdue',
                    title: "Invoice overdue {$days} day" . ($days === 1 ? '' : 's') . ": {$inv['customer_name']}",
                    body: "{$inv['invoice_number']} · {$inv['currency']} " . number_format(Money::toMinor($inv['outstanding']) / 100, 2) . " outstanding · due {$inv['due_on']}",
                    linkType: 'invoice',
                    linkId: $inv['id'],
                    linkFragment: null,
                    dedupeKey: "invoice:{$inv['id']}:overdue:{$bucket}:u{$userId}",
                );
                $sent++;
            }

            $this->openTask($inv, $days, $recipients);
        }

        return $sent;
    }

    /**
     * @param array<string,mixed> $inv one row of InvoiceRepository::overdueForReminder()
     * @param list<int> $recipients accounts users of the branch first, then the invoice's creator
     */
    private function openTask(array $inv, int $days, array $recipients): void
    {
        $assignee = $recipients[0] ?? null;
        if ($assignee === null || $this->tasks->hasPendingSystemTask('invoice', $inv['id'])) {
            return;
        }

        $span = intdiv(max($days, 1) - 1, 28);
        $this->tasks->createOnce([
            'public_id' => Ulid::generate(),
            'title' => "Collect payment: {$inv['customer_name']} · {$inv['invoice_number']}",
            'description' => "{$inv['currency']} " . number_format(Money::toMinor($inv['outstanding']) / 100, 2) . " outstanding, {$days} day" . ($days === 1 ? '' : 's') . " past the {$inv['due_on']} due date.",
            'related_type' => 'invoice',
            'related_id' => $inv['id'],
            'branch_id' => $inv['branch_id'],
            'assigned_to' => $assignee,
            'priority' => $days >= 30 ? 'urgent' : ($days >= 7 ? 'high' : 'medium'),
            'due_date' => gmdate('Y-m-d'),
            'dedupe_key' => "invoice:{$inv['id']}:overdue-task:{$span}",
        ]);
    }
}
