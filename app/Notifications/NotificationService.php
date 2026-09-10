<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Lead;
use App\Repositories\NotificationRepository;
use App\Support\Logger;
use Throwable;

/**
 * Creates in-app notifications. Delivery failure is logged, never fatal to the
 * business operation that triggered it. Automated callers pass a `dedupeKey` so
 * re-runs (cron) do not pile up duplicates.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repo,
        private readonly Logger $logger,
    ) {
    }

    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $linkType = null,
        ?int $linkId = null,
        ?string $linkFragment = null,
        ?string $dedupeKey = null,
    ): void {
        try {
            $this->repo->create($userId, $type, $title, $body, $linkType, $linkId, $linkFragment, $dedupeKey);
        } catch (Throwable $e) {
            $this->logger->error('notification failed ({type} -> user {user})', [
                'type' => $type, 'user' => $userId, 'exception' => $e,
            ]);
        }
    }

    public function leadAssigned(Lead $lead, int $assigneeUserId, ?string $byName = null): void
    {
        if ($assigneeUserId <= 0) {
            return;
        }

        $this->notify(
            userId: $assigneeUserId,
            type: 'lead_assigned',
            title: "Lead assigned: {$lead->name}",
            body: trim(($byName !== null ? "Assigned by {$byName}. " : '') . "{$lead->leadNumber} · {$lead->phone}"),
            linkType: 'lead',
            linkId: $lead->id,
            linkFragment: null,
            dedupeKey: null, // manual assignment — allow repeats
        );
    }

    public function leadFollowupDue(int $assigneeUserId, Lead $lead, string $dueDate): void
    {
        $this->notify(
            userId: $assigneeUserId,
            type: 'lead_followup_due',
            title: "Follow-up due: {$lead->name}",
            body: "{$lead->leadNumber} · due {$dueDate}",
            linkType: 'lead',
            linkId: $lead->id,
            linkFragment: 'followups',
            dedupeKey: "lead_followup:{$lead->id}:{$dueDate}",
        );
    }
}
