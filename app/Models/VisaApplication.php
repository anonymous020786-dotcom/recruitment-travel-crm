<?php

declare(strict_types=1);

namespace App\Models;

/** A candidate's visa application (`visa_applications` joined to candidate/application). Immutable read model. */
final class VisaApplication
{
    /** No further normal moves; an override may reopen. */
    public const CLOSED = ['rejected', 'expired', 'cancelled'];

    /** An approved visa within this many days of expiry is flagged. */
    public const EXPIRING_DAYS = 30;

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $candidateId,
        public readonly string $candidatePublicId,
        public readonly string $candidateName,
        public readonly string $candidateNumber,
        public readonly int $branchId,
        public readonly ?int $assignedTo,
        public readonly ?int $applicationId,
        public readonly ?string $applicationPublicId,
        public readonly ?string $applicationNumber,
        public readonly ?string $jobTitle,
        public readonly string $country,
        public readonly ?string $visaType,
        public readonly ?string $visaNumber,
        public readonly ?string $referenceNumber,
        public readonly ?string $sponsor,
        public readonly ?string $submissionDate,
        public readonly ?string $approvalDate,
        public readonly ?string $expiryDate,
        public readonly string $status,
        public readonly ?string $notes,
        public readonly int $recordVersion,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            candidateId: (int) $r['candidate_id'],
            candidatePublicId: (string) ($r['candidate_public_id'] ?? ''),
            candidateName: (string) ($r['candidate_name'] ?? ''),
            candidateNumber: (string) ($r['candidate_number'] ?? ''),
            branchId: (int) ($r['branch_id'] ?? 0),
            assignedTo: isset($r['assigned_to']) ? (int) $r['assigned_to'] : (isset($r['candidate_counselor']) ? (int) $r['candidate_counselor'] : null),
            applicationId: isset($r['application_id']) ? (int) $r['application_id'] : null,
            applicationPublicId: $r['application_public_id'] ?? null,
            applicationNumber: $r['application_number'] ?? null,
            jobTitle: $r['job_title'] ?? null,
            country: (string) $r['country'],
            visaType: $r['visa_type'] ?? null,
            visaNumber: $r['visa_number'] ?? null,
            referenceNumber: $r['reference_number'] ?? null,
            sponsor: $r['sponsor'] ?? null,
            submissionDate: $r['submission_date'] ?? null,
            approvalDate: $r['approval_date'] ?? null,
            expiryDate: $r['expiry_date'] ?? null,
            status: (string) $r['status'],
            notes: $r['notes'] ?? null,
            recordVersion: (int) $r['record_version'],
            createdAt: (string) $r['created_at'],
        );
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    public static function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    public function label(): string
    {
        return self::statusLabel($this->status);
    }

    /** For an approved visa: 'expired' | 'expiring' | 'valid'; null when there is nothing to judge. */
    public function expiryState(?string $today = null): ?string
    {
        if ($this->status !== 'approved' || $this->expiryDate === null) {
            return null;
        }
        $today ??= gmdate('Y-m-d');
        if ($this->expiryDate < $today) {
            return 'expired';
        }
        $days = (int) ((strtotime($this->expiryDate) - strtotime($today)) / 86400);

        return $days <= self::EXPIRING_DAYS ? 'expiring' : 'valid';
    }
}
