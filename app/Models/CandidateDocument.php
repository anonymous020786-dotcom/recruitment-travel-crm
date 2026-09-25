<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One uploaded document (`candidate_documents` joined to `document_types`
 * and the uploader/verifier). Immutable read model. `storagePath` is never
 * shown to a browser directly — only `DocumentController::download()` ever
 * reads it, after authorization.
 */
final class CandidateDocument
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $candidateId,
        public readonly int $documentTypeId,
        public readonly string $documentTypeKey,
        public readonly string $documentTypeLabel,
        public readonly string $storagePath,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly string $status,
        public readonly ?string $rejectionReason,
        public readonly ?string $issuedOn,
        public readonly ?string $expiresAt,
        public readonly int $uploadedBy,
        public readonly ?string $uploadedByName,
        public readonly ?int $verifiedBy,
        public readonly ?string $verifiedByName,
        public readonly ?string $verifiedAt,
        public readonly int $recordVersion,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $storageDisk = 'private',
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            candidateId: (int) $r['candidate_id'],
            documentTypeId: (int) $r['document_type_id'],
            documentTypeKey: (string) $r['type_key'],
            documentTypeLabel: (string) $r['type_label'],
            storagePath: (string) $r['storage_path'],
            originalName: (string) $r['original_name'],
            mimeType: (string) $r['mime_type'],
            extension: (string) $r['extension'],
            sizeBytes: (int) $r['size_bytes'],
            sha256: (string) $r['sha256'],
            status: (string) $r['status'],
            rejectionReason: $r['rejection_reason'] ?? null,
            issuedOn: $r['issued_on'] ?? null,
            expiresAt: $r['expires_at'] ?? null,
            uploadedBy: (int) $r['uploaded_by'],
            uploadedByName: $r['uploader_name'] ?? null,
            verifiedBy: isset($r['verified_by']) ? (int) $r['verified_by'] : null,
            verifiedByName: $r['verifier_name'] ?? null,
            verifiedAt: $r['verified_at'] ?? null,
            recordVersion: (int) $r['record_version'],
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
            storageDisk: (string) ($r['storage_disk'] ?? 'private'),
        );
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pending', 'uploaded' => 'Uploaded', 'under_review' => 'Under review',
            'verified' => 'Verified', 'rejected' => 'Rejected', 'expired' => 'Expired',
            default => ucfirst($this->status),
        };
    }

    public function isExpired(?string $today = null): bool
    {
        $today ??= gmdate('Y-m-d');

        return $this->expiresAt !== null && $this->expiresAt < $today;
    }

    public function isExpiringSoon(int $withinDays = 60, ?string $today = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        $today ??= gmdate('Y-m-d');
        $days = (int) ((strtotime($this->expiresAt) - strtotime($today)) / 86400);

        return $days >= 0 && $days <= $withinDays;
    }

    public function sizeLabel(): string
    {
        $kb = $this->sizeBytes / 1024;

        return $kb >= 1024 ? round($kb / 1024, 1) . ' MB' : round($kb, 1) . ' KB';
    }
}
