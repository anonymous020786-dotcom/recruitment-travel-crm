<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

final class NotificationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Insert a notification. When $dedupeKey is given, a repeat insert is a
     * no-op (unique index) — automated notifications stay idempotent.
     *
     * @return bool true if a row was created
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        ?string $body,
        ?string $linkType,
        ?int $linkId,
        ?string $linkFragment,
        ?string $dedupeKey,
    ): bool {
        $affected = $this->db->affectingStatement(
            'INSERT ' . ($dedupeKey !== null ? 'IGNORE ' : '') . 'INTO notifications
                (user_id, type, title, body, link_type, link_id, link_fragment, dedupe_key, created_at)
             VALUES (:uid, :type, :title, :body, :ltype, :lid, :lfrag, :dedupe, UTC_TIMESTAMP())',
            [
                'uid'    => $userId,
                'type'   => mb_substr($type, 0, 60),
                'title'  => mb_substr($title, 0, 200),
                'body'   => $body !== null ? mb_substr($body, 0, 500) : null,
                'ltype'  => $linkType,
                'lid'    => $linkId,
                'lfrag'  => $linkFragment,
                'dedupe' => $dedupeKey !== null ? mb_substr($dedupeKey, 0, 150) : null,
            ],
        );

        return $affected > 0;
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL',
            ['uid' => $userId],
            0,
        );
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $userId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));

        return $this->db->select(
            "SELECT id, type, title, body, link_type, link_id, link_fragment, read_at, created_at
             FROM notifications WHERE user_id = :uid ORDER BY id DESC LIMIT {$limit}",
            ['uid' => $userId],
        );
    }

    public function markRead(int $id, int $userId): int
    {
        return $this->db->affectingStatement(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :uid AND read_at IS NULL',
            ['id' => $id, 'uid' => $userId],
        );
    }

    public function markAllRead(int $userId): int
    {
        return $this->db->affectingStatement(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = :uid AND read_at IS NULL',
            ['uid' => $userId],
        );
    }
}
