<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A logged contact touchpoint (`communication_logs`) — a call, WhatsApp/SMS/
 * email exchange, meeting, or free-form note about contact made. Polymorphic
 * (`related_type`/`related_id`); Phase 2 only writes `related_type = 'lead'`.
 * Immutable read model.
 */
final class CommunicationLog
{
    public function __construct(
        public readonly int $id,
        public readonly string $relatedType,
        public readonly int $relatedId,
        public readonly int $userId,
        public readonly string $channel,
        public readonly string $direction,
        public readonly string $summary,
        public readonly string $occurredAt,
        public readonly string $createdAt,
        public readonly ?string $userName = null,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            relatedType: (string) $r['related_type'],
            relatedId: (int) $r['related_id'],
            userId: (int) $r['user_id'],
            channel: (string) $r['channel'],
            direction: (string) $r['direction'],
            summary: (string) $r['summary'],
            occurredAt: (string) $r['occurred_at'],
            createdAt: (string) $r['created_at'],
            userName: $r['user_name'] ?? null,
        );
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'whatsapp' => 'WhatsApp',
            'sms'      => 'SMS',
            'email'    => 'Email',
            'meeting'  => 'Meeting',
            'note'     => 'Note',
            default    => 'Call',
        };
    }

    public function directionLabel(): string
    {
        return match ($this->direction) {
            'inbound'  => 'Inbound',
            'internal' => 'Internal',
            default    => 'Outbound',
        };
    }
}
