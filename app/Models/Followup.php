<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A scheduled lead follow-up (call / message / meeting). Immutable read model,
 * hydrated from `lead_followups` optionally joined to its lead + assignee.
 */
final class Followup
{
    public function __construct(
        public readonly int $id,
        public readonly int $leadId,
        public readonly int $assignedTo,
        public readonly int $branchId,
        public readonly string $dueDate,
        public readonly ?string $dueTime,
        public readonly string $channel,
        public readonly ?string $subject,
        public readonly string $status,
        public readonly ?string $outcome,
        public readonly ?string $completedAt,
        public readonly int $createdBy,
        public readonly string $createdAt,
        public readonly ?string $assigneeName = null,
        public readonly ?string $leadName = null,
        public readonly ?string $leadNumber = null,
        public readonly ?string $leadPublicId = null,
        public readonly ?string $leadPhone = null,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            leadId: (int) $r['lead_id'],
            assignedTo: (int) $r['assigned_to'],
            branchId: (int) $r['branch_id'],
            dueDate: (string) $r['due_date'],
            dueTime: isset($r['due_time']) ? substr((string) $r['due_time'], 0, 5) : null,
            channel: (string) $r['channel'],
            subject: $r['subject'] ?? null,
            status: (string) $r['status'],
            outcome: $r['outcome'] ?? null,
            completedAt: $r['completed_at'] ?? null,
            createdBy: (int) ($r['created_by'] ?? 0),
            createdAt: (string) ($r['created_at'] ?? ''),
            assigneeName: $r['assignee_name'] ?? null,
            leadName: $r['lead_name'] ?? null,
            leadNumber: $r['lead_number'] ?? null,
            leadPublicId: $r['lead_public_id'] ?? null,
            leadPhone: $r['lead_phone'] ?? null,
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isOverdue(?string $today = null): bool
    {
        $today ??= gmdate('Y-m-d');

        return $this->isPending() && $this->dueDate < $today;
    }

    public function isDueToday(?string $today = null): bool
    {
        $today ??= gmdate('Y-m-d');

        return $this->isPending() && $this->dueDate === $today;
    }

    public function dueLabel(): string
    {
        return $this->dueTime !== null ? "{$this->dueDate} {$this->dueTime}" : $this->dueDate;
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'whatsapp' => 'WhatsApp',
            'sms'      => 'SMS',
            'email'    => 'Email',
            'meeting'  => 'Meeting',
            'other'    => 'Other',
            default    => 'Call',
        };
    }
}
