<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One row of a candidate's required-document checklist
 * (`candidate_document_checklist` joined to `document_types` and, when
 * satisfied, the winning `candidate_documents` row). Immutable read model.
 */
final class ChecklistItem
{
    public function __construct(
        public readonly int $candidateId,
        public readonly int $documentTypeId,
        public readonly string $typeKey,
        public readonly string $typeLabel,
        public readonly string $category,
        public readonly bool $isRequired,
        public readonly ?int $satisfiedDocumentId,
        public readonly ?string $satisfiedStatus,
        public readonly ?string $satisfiedPublicId,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            candidateId: (int) $r['candidate_id'],
            documentTypeId: (int) $r['document_type_id'],
            typeKey: (string) $r['type_key'],
            typeLabel: (string) $r['type_label'],
            category: (string) $r['category'],
            isRequired: (bool) $r['is_required'],
            satisfiedDocumentId: isset($r['satisfied_document_id']) ? (int) $r['satisfied_document_id'] : null,
            satisfiedStatus: $r['satisfied_status'] ?? null,
            satisfiedPublicId: $r['satisfied_public_id'] ?? null,
        );
    }

    public function isSatisfied(): bool
    {
        return $this->satisfiedDocumentId !== null && $this->satisfiedStatus === 'verified';
    }
}
