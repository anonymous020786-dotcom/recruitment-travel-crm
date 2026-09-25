<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A generic task (`tasks`, `related_type`/`related_id` polymorphic link).
 * Immutable read model. Only the candidate-linked slice is wired up so far
 * (see CandidateService); the table and this model are generic on purpose so
 * later phases (applications, visa, travel...) can reuse both without
 * duplicating the shape.
 */
final class Task
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $relatedType,
        public readonly ?int $relatedId,
        public readonly int $branchId,
        public readonly int $assignedTo,
        public readonly ?string $assigneeName,
        public readonly string $priority,
        public readonly ?string $dueDate,
        public readonly ?string $dueTime,
        public readonly string $status,
        public readonly ?string $completedAt,
        public readonly string $createdAt,
        public readonly ?int $createdBy = null,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            title: (string) $r['title'],
            description: $r['description'] ?? null,
            relatedType: (string) $r['related_type'],
            relatedId: isset($r['related_id']) ? (int) $r['related_id'] : null,
            branchId: (int) $r['branch_id'],
            assignedTo: (int) $r['assigned_to'],
            assigneeName: $r['assignee_name'] ?? null,
            priority: (string) $r['priority'],
            dueDate: $r['due_date'] ?? null,
            dueTime: isset($r['due_time']) ? substr((string) $r['due_time'], 0, 5) : null,
            status: (string) $r['status'],
            completedAt: $r['completed_at'] ?? null,
            createdAt: (string) $r['created_at'],
            createdBy: isset($r['created_by']) ? (int) $r['created_by'] : null,
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isOverdue(?string $today = null): bool
    {
        $today ??= gmdate('Y-m-d');

        return $this->isPending() && $this->dueDate !== null && $this->dueDate < $today;
    }
}
