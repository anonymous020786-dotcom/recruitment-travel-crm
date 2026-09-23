<?php

declare(strict_types=1);

namespace App\Models;

/** A candidate's application to a job (`applications` joined to candidate/job/employer names). Immutable read model. */
final class Application
{
    public const TERMINAL = ['placed', 'rejected', 'cancelled'];

    /** @param array<string,mixed>|null $matchBreakdown */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $applicationNumber,
        public readonly int $candidateId,
        public readonly string $candidatePublicId,
        public readonly string $candidateNumber,
        public readonly string $candidateName,
        public readonly int $jobId,
        public readonly string $jobPublicId,
        public readonly string $jobNumber,
        public readonly string $jobTitle,
        public readonly int $employerId,
        public readonly string $employerPublicId,
        public readonly string $employerName,
        public readonly int $branchId,
        public readonly string $status,
        public readonly ?float $matchScore,
        public readonly ?array $matchBreakdown,
        public readonly ?int $assignedTo,
        public readonly ?string $assignedToName,
        public readonly string $appliedAt,
        public readonly ?string $closedAt,
        public readonly ?string $cancelReason,
        public readonly int $recordVersion,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        $decoded = is_string($r['match_breakdown'] ?? null) ? json_decode($r['match_breakdown'], true) : null;

        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            applicationNumber: (string) $r['application_number'],
            candidateId: (int) $r['candidate_id'],
            candidatePublicId: (string) ($r['candidate_public_id'] ?? ''),
            candidateNumber: (string) ($r['candidate_number'] ?? ''),
            candidateName: (string) ($r['candidate_name'] ?? ''),
            jobId: (int) $r['job_id'],
            jobPublicId: (string) ($r['job_public_id'] ?? ''),
            jobNumber: (string) ($r['job_number'] ?? ''),
            jobTitle: (string) ($r['job_title'] ?? ''),
            employerId: (int) $r['employer_id'],
            employerPublicId: (string) ($r['employer_public_id'] ?? ''),
            employerName: (string) ($r['employer_name'] ?? ''),
            branchId: (int) $r['branch_id'],
            status: (string) $r['status'],
            matchScore: isset($r['match_score']) ? (float) $r['match_score'] : null,
            matchBreakdown: is_array($decoded) ? $decoded : null,
            assignedTo: isset($r['assigned_to']) ? (int) $r['assigned_to'] : null,
            assignedToName: $r['assigned_to_name'] ?? null,
            appliedAt: (string) $r['applied_at'],
            closedAt: $r['closed_at'] ?? null,
            cancelReason: $r['cancel_reason'] ?? null,
            recordVersion: (int) $r['record_version'],
            createdAt: (string) $r['created_at'],
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public static function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    public function label(): string
    {
        return self::statusLabel($this->status);
    }
}
