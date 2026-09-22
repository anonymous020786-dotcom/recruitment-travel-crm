<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ChecklistItem;
use App\Support\Db;

/**
 * SQL for `candidate_document_checklist`. Rows are seeded lazily from
 * `document_types.is_required_default` the first time a candidate's
 * checklist is read (`ensureSeeded()` is an idempotent `INSERT IGNORE`) —
 * this also means a document type added after a candidate already exists
 * still backfills onto their checklist the next time it's viewed, with no
 * migration/backfill script needed.
 */
final class ChecklistRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function ensureSeeded(int $candidateId): void
    {
        $this->db->affectingStatement(
            'INSERT IGNORE INTO candidate_document_checklist (candidate_id, document_type_id, is_required)
             SELECT :cid, dt.id, 1 FROM document_types dt WHERE dt.is_required_default = 1 AND dt.is_active = 1',
            ['cid' => $candidateId],
        );
    }

    /** @return list<ChecklistItem> */
    public function forCandidate(int $candidateId): array
    {
        $this->ensureSeeded($candidateId);

        $rows = $this->db->select(
            'SELECT cl.candidate_id, cl.document_type_id, dt.key_name AS type_key, dt.label AS type_label, dt.category,
                    cl.is_required, cl.satisfied_document_id, cd.status AS satisfied_status, cd.public_id AS satisfied_public_id
             FROM candidate_document_checklist cl
             JOIN document_types dt ON dt.id = cl.document_type_id
             LEFT JOIN candidate_documents cd ON cd.id = cl.satisfied_document_id
             WHERE cl.candidate_id = :cid
             ORDER BY dt.sort_order, dt.label',
            ['cid' => $candidateId],
        );

        return array_map([ChecklistItem::class, 'fromRow'], $rows);
    }

    /** Called when a document is verified — links it as the checklist item's satisfying document. */
    public function markSatisfied(int $candidateId, int $documentTypeId, int $documentId): void
    {
        $this->db->affectingStatement(
            'INSERT INTO candidate_document_checklist (candidate_id, document_type_id, is_required, satisfied_document_id)
             VALUES (:cid, :tid, 1, :did)
             ON DUPLICATE KEY UPDATE satisfied_document_id = VALUES(satisfied_document_id)',
            ['cid' => $candidateId, 'tid' => $documentTypeId, 'did' => $documentId],
        );
    }

    /** Cron companion to markExpiredBefore(): a checklist item satisfied by a now-expired document is no longer satisfied. */
    public function clearSatisfiedForExpiredDocuments(): int
    {
        return $this->db->affectingStatement(
            "UPDATE candidate_document_checklist cl
             JOIN candidate_documents cd ON cd.id = cl.satisfied_document_id
             SET cl.satisfied_document_id = NULL
             WHERE cd.status = 'expired'",
        );
    }

    public function setRequired(int $candidateId, int $documentTypeId, bool $required): void
    {
        $this->db->affectingStatement(
            'INSERT INTO candidate_document_checklist (candidate_id, document_type_id, is_required)
             VALUES (:cid, :tid, :req)
             ON DUPLICATE KEY UPDATE is_required = VALUES(is_required)',
            ['cid' => $candidateId, 'tid' => $documentTypeId, 'req' => $required ? 1 : 0],
        );
    }
}
