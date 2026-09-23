<?php

declare(strict_types=1);

namespace App\Models;

/** One interview round for an application (`interviews` joined to application/candidate/job/employer names). Immutable read model. */
final class Interview
{
    public const OPEN = ['scheduled', 'confirmed'];

    public const TYPES = ['in_person' => 'In person', 'video' => 'Video call', 'telephonic' => 'Telephonic', 'client_visit' => 'Client visit'];

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $applicationId,
        public readonly string $applicationPublicId,
        public readonly string $applicationNumber,
        public readonly int $branchId,
        public readonly ?int $assignedTo,
        public readonly int $candidateId,
        public readonly string $candidatePublicId,
        public readonly string $candidateName,
        public readonly int $jobId,
        public readonly string $jobPublicId,
        public readonly string $jobTitle,
        public readonly int $employerId,
        public readonly string $employerName,
        public readonly int $roundNo,
        public readonly string $type,
        public readonly string $scheduledDate,
        public readonly ?string $scheduledTime,
        public readonly ?string $location,
        public readonly ?string $meetingLink,
        public readonly ?string $interviewer,
        public readonly string $status,
        public readonly string $result,
        public readonly ?string $feedback,
        public readonly ?string $notes,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            applicationId: (int) $r['application_id'],
            applicationPublicId: (string) ($r['application_public_id'] ?? ''),
            applicationNumber: (string) ($r['application_number'] ?? ''),
            branchId: (int) ($r['branch_id'] ?? 0),
            assignedTo: isset($r['assigned_to']) ? (int) $r['assigned_to'] : null,
            candidateId: (int) $r['candidate_id'],
            candidatePublicId: (string) ($r['candidate_public_id'] ?? ''),
            candidateName: (string) ($r['candidate_name'] ?? ''),
            jobId: (int) $r['job_id'],
            jobPublicId: (string) ($r['job_public_id'] ?? ''),
            jobTitle: (string) ($r['job_title'] ?? ''),
            employerId: (int) $r['employer_id'],
            employerName: (string) ($r['employer_name'] ?? ''),
            roundNo: (int) $r['round_no'],
            type: (string) $r['type'],
            scheduledDate: (string) $r['scheduled_date'],
            scheduledTime: isset($r['scheduled_time']) ? substr((string) $r['scheduled_time'], 0, 5) : null,
            location: $r['location'] ?? null,
            meetingLink: $r['meeting_link'] ?? null,
            interviewer: $r['interviewer'] ?? null,
            status: (string) $r['status'],
            result: (string) $r['result'],
            feedback: $r['feedback'] ?? null,
            notes: $r['notes'] ?? null,
            createdAt: (string) $r['created_at'],
        );
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return $this->status === 'completed' ? 'Completed (on hold)' : ucwords(str_replace('_', ' ', $this->status));
    }

    /** "2026-09-30 14:30", or just the date when no time was set. */
    public function whenLabel(): string
    {
        return $this->scheduledDate . ($this->scheduledTime !== null ? ' ' . $this->scheduledTime : '');
    }
}
