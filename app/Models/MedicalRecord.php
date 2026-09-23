<?php

declare(strict_types=1);

namespace App\Models;

/** A candidate's medical examination (`medical_records` joined to candidate/application). Immutable read model. */
final class MedicalRecord
{
    /** Statuses in which the exam is still in progress (no verdict yet). */
    public const OPEN = ['pending', 'scheduled', 'completed'];

    /** A fit certificate within this many days of expiry is flagged. */
    public const EXPIRING_DAYS = 30;

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $candidateId,
        public readonly string $candidatePublicId,
        public readonly string $candidateName,
        public readonly string $candidateNumber,
        public readonly int $branchId,
        public readonly ?int $applicationId,
        public readonly ?string $applicationPublicId,
        public readonly ?string $applicationNumber,
        public readonly ?string $medicalCenter,
        public readonly ?string $appointmentDate,
        public readonly ?string $medicalDate,
        public readonly ?string $reportDate,
        public readonly string $result,
        public readonly ?string $expiresAt,
        public readonly string $status,
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
            candidateId: (int) $r['candidate_id'],
            candidatePublicId: (string) ($r['candidate_public_id'] ?? ''),
            candidateName: (string) ($r['candidate_name'] ?? ''),
            candidateNumber: (string) ($r['candidate_number'] ?? ''),
            branchId: (int) ($r['branch_id'] ?? 0),
            applicationId: isset($r['application_id']) ? (int) $r['application_id'] : null,
            applicationPublicId: $r['application_public_id'] ?? null,
            applicationNumber: $r['application_number'] ?? null,
            medicalCenter: $r['medical_center'] ?? null,
            appointmentDate: $r['appointment_date'] ?? null,
            medicalDate: $r['medical_date'] ?? null,
            reportDate: $r['report_date'] ?? null,
            result: (string) $r['result'],
            expiresAt: $r['expires_at'] ?? null,
            status: (string) $r['status'],
            notes: $r['notes'] ?? null,
            createdAt: (string) $r['created_at'],
        );
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'completed' => 'Awaiting report',
            'retest'    => 'Retest needed',
            default     => ucfirst($this->status),
        };
    }

    /** For a fit certificate: 'expired' | 'expiring' | 'valid'; null when there is no expiry to judge. */
    public function expiryState(?string $today = null): ?string
    {
        if ($this->status !== 'fit' || $this->expiresAt === null) {
            return null;
        }
        $today ??= gmdate('Y-m-d');
        if ($this->expiresAt < $today) {
            return 'expired';
        }
        $days = (int) ((strtotime($this->expiresAt) - strtotime($today)) / 86400);

        return $days <= self::EXPIRING_DAYS ? 'expiring' : 'valid';
    }
}
