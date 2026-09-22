<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\Gate;
use App\Domain\StatusMachine;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\User;
use App\Repositories\CandidateDocumentRepository;
use App\Repositories\ChecklistRepository;
use App\Repositories\DocumentTypeRepository;
use App\Support\DocumentUpload;
use App\Support\Ulid;

/**
 * Document business workflows: upload (runs the accept pipeline, never
 * trusts client metadata), authorized access logging for download/preview,
 * and the verify/reject lifecycle. Storage details live in
 * App\Support\DocumentUpload; this service owns authorization, the DB row,
 * and the audit trail.
 */
final class DocumentService
{
    public function __construct(
        private readonly CandidateDocumentRepository $documents,
        private readonly DocumentTypeRepository $types,
        private readonly DocumentUpload $upload,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly StatusMachine $statuses,
        private readonly ChecklistRepository $checklist,
    ) {
    }

    /** @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file */
    public function upload(
        Candidate $candidate,
        int $documentTypeId,
        array $file,
        ?string $issuedOn,
        ?string $expiresAt,
        User $actor,
    ): CandidateDocument {
        if (!$this->gate->forUser($actor)->allows('uploadDocument', $candidate)) {
            throw AuthorizationException::forPermission('documents.upload');
        }

        $type = $this->types->find($documentTypeId);
        if ($type === null || !$type->isActive) {
            throw new ValidationException(['document_type_id' => ['Choose a valid document type.']]);
        }

        $stored = $this->upload->store($file, $type->allowedMime, $type->maxSizeKb);

        $id = $this->documents->create([
            'public_id'        => Ulid::generate(),
            'candidate_id'     => $candidate->id,
            'document_type_id' => $type->id,
            'storage_path'     => $stored['storage_path'],
            'original_name'    => mb_substr((string) ($file['name'] ?? 'document'), 0, 200),
            'mime_type'        => $stored['mime_type'],
            'extension'        => $stored['extension'],
            'size_bytes'       => $stored['size_bytes'],
            'sha256'           => $stored['sha256'],
            'status'           => 'uploaded',
            'issued_on'        => $issuedOn,
            'expires_at'       => $expiresAt,
            'uploaded_by'      => $actor->id,
        ]);

        $this->audit->log('document_uploaded', 'candidates', 'candidate', $candidate->id, null, [
            'document_id' => $id, 'type' => $type->keyName,
        ], null, $actor);

        $row = $this->documents->findInCandidate($id, $candidate->id);
        if ($row === null) {
            throw new \RuntimeException('Document row vanished immediately after insert.');
        }

        return $row;
    }

    /** Authorizes a view/download/preview and records it — call before streaming the file. */
    public function authorizeAccess(CandidateDocument $document, User $actor, string $action): void
    {
        $permission = $action === 'download' ? 'documents.download' : 'documents.view';
        if (!$this->gate->forUser($actor)->allows($permission)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    public function logAccess(CandidateDocument $document, User $actor, string $action, ?string $ipBinary): void
    {
        $this->documents->logAccess($document->id, $actor->id, $action, $ipBinary);
    }

    public function delete(Candidate $candidate, int $documentId, User $actor): void
    {
        if (!$this->gate->forUser($actor)->allows('documents.delete')) {
            throw AuthorizationException::forPermission('documents.delete');
        }
        $document = $this->documents->findInCandidate($documentId, $candidate->id);
        if ($document === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Document not found.', [], 404);
        }

        if ($this->documents->delete($documentId, $candidate->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Document not found.', [], 404);
        }
        $this->audit->log('document_deleted', 'candidates', 'candidate', $candidate->id, [
            'document_id' => $documentId, 'original_name' => $document->originalName,
        ], null, null, $actor);

        @unlink($this->upload->absolutePath($document->storagePath));
    }

    public function startReview(Candidate $candidate, int $documentId, User $actor, int $expectedVersion): CandidateDocument
    {
        if (!$this->gate->forUser($actor)->allows('documents.verify') && !$this->gate->forUser($actor)->allows('documents.reject')) {
            throw AuthorizationException::forPermission('documents.verify');
        }

        return $this->transitionTo($candidate, $documentId, $expectedVersion, 'under_review', [], 'document_review_started', $actor);
    }

    public function verify(Candidate $candidate, int $documentId, User $actor, int $expectedVersion): CandidateDocument
    {
        if (!$this->gate->forUser($actor)->allows('documents.verify')) {
            throw AuthorizationException::forPermission('documents.verify');
        }

        $verified = $this->transitionTo($candidate, $documentId, $expectedVersion, 'verified', [
            'verified_by' => $actor->id,
            'verified_at' => gmdate('Y-m-d H:i:s'),
            'rejection_reason' => null,
        ], 'document_verified', $actor);

        $this->checklist->markSatisfied($candidate->id, $verified->documentTypeId, $verified->id);

        return $verified;
    }

    public function reject(Candidate $candidate, int $documentId, string $reason, User $actor, int $expectedVersion): CandidateDocument
    {
        if (!$this->gate->forUser($actor)->allows('documents.reject')) {
            throw AuthorizationException::forPermission('documents.reject');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['rejection_reason' => ['Say why this document is being rejected.']]);
        }

        return $this->transitionTo($candidate, $documentId, $expectedVersion, 'rejected', [
            'rejection_reason' => mb_substr($reason, 0, 255),
            'verified_by' => null,
            'verified_at' => null,
        ], 'document_rejected', $actor);
    }

    /** Cron entry point: verified documents past their expiry date → 'expired'. Idempotent (only matches status='verified'). */
    public function expireDue(?string $today = null): int
    {
        $today ??= gmdate('Y-m-d');

        $expired = $this->documents->markExpiredBefore($today);
        if ($expired > 0) {
            $this->checklist->clearSatisfiedForExpiredDocuments();
        }

        return $expired;
    }

    public function toggleChecklistRequirement(Candidate $candidate, int $documentTypeId, bool $required, User $actor): void
    {
        if (!$this->gate->forUser($actor)->allows('documents.checklist.manage')) {
            throw AuthorizationException::forPermission('documents.checklist.manage');
        }
        $this->checklist->setRequired($candidate->id, $documentTypeId, $required);
        $this->audit->log('checklist_updated', 'candidates', 'candidate', $candidate->id, null, [
            'document_type_id' => $documentTypeId, 'is_required' => $required,
        ], null, $actor);
    }

    /** @param array<string,mixed> $extraFields */
    private function transitionTo(Candidate $candidate, int $documentId, int $expectedVersion, string $toStatus, array $extraFields, string $auditAction, User $actor): CandidateDocument
    {
        $document = $this->documents->findInCandidate($documentId, $candidate->id);
        if ($document === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Document not found.', [], 404);
        }

        $this->statuses->assert('document', $document->status, $toStatus);

        $affected = $this->documents->updateFields($documentId, ['status' => $toStatus] + $extraFields, $expectedVersion);
        if ($affected === 0) {
            throw new StaleRecordException('document', $document->publicId);
        }

        $this->audit->log($auditAction, 'candidates', 'candidate', $candidate->id, ['status' => $document->status], ['status' => $toStatus], null, $actor);

        $fresh = $this->documents->findInCandidate($documentId, $candidate->id);
        if ($fresh === null) {
            throw new \RuntimeException('Document row vanished immediately after transition.');
        }

        return $fresh;
    }
}
