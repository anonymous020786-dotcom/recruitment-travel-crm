<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Models\CandidateDocument;
use App\Support\Db;

/**
 * SQL for `candidate_documents`. Download/preview are keyed only by the
 * document's own public_id (docs/00-ARCHITECTURE.md §11.3), so
 * `findByPublicId` joins through to `candidates` to apply branch scope
 * without the caller already knowing which candidate it belongs to; every
 * other method scopes by `candidate_id` once that's known.
 */
final class CandidateDocumentRepository
{
    private const COLUMNS = "d.id, d.public_id, d.candidate_id, d.document_type_id,
        dt.key_name AS type_key, dt.label AS type_label,
        d.storage_path, d.original_name, d.mime_type, d.extension, d.size_bytes, d.sha256,
        d.status, d.rejection_reason, d.issued_on, d.expires_at,
        d.uploaded_by, u.name AS uploader_name, d.verified_by, v.name AS verifier_name, d.verified_at,
        d.record_version, d.created_at, d.updated_at";

    private const JOINS = 'FROM candidate_documents d
        JOIN document_types dt ON dt.id = d.document_type_id
        LEFT JOIN users u ON u.id = d.uploaded_by
        LEFT JOIN users v ON v.id = d.verified_by';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<CandidateDocument> newest first */
    public function forCandidate(int $candidateId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE d.candidate_id = :cid ORDER BY d.id DESC',
            ['cid' => $candidateId],
        );

        return array_map([CandidateDocument::class, 'fromRow'], $rows);
    }

    public function findInCandidate(int $id, int $candidateId): ?CandidateDocument
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE d.id = :id AND d.candidate_id = :cid',
            ['id' => $id, 'cid' => $candidateId],
        );

        return $row ? CandidateDocument::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId, BranchScope $scope): ?CandidateDocument
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . "
             JOIN candidates c ON c.id = d.candidate_id
             WHERE d.public_id = :pid AND {$branchSql}",
            ['pid' => $publicId] + $bind,
        );

        return $row ? CandidateDocument::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('candidate_documents', $data);
    }

    /**
     * Optimistic update (verification etc.) — only writes when record_version
     * matches; bumps it. @param array<string,mixed> $changes
     */
    public function updateFields(int $id, array $changes, int $expectedVersion): int
    {
        $set = ['record_version = record_version + 1', 'updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id, 'ver' => $expectedVersion];
        foreach ($changes as $col => $val) {
            $set[] = "`{$col}` = :c_{$col}";
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE candidate_documents SET ' . implode(', ', $set) . ' WHERE id = :id AND record_version = :ver',
            $bind,
        );
    }

    public function delete(int $id, int $candidateId): int
    {
        return $this->db->affectingStatement(
            'DELETE FROM candidate_documents WHERE id = :id AND candidate_id = :cid',
            ['id' => $id, 'cid' => $candidateId],
        );
    }

    public function logAccess(int $documentId, int $userId, string $action, ?string $ipBinary): void
    {
        $this->db->insertRow('document_access_log', [
            'document_id' => $documentId, 'user_id' => $userId, 'action' => $action, 'ip_address' => $ipBinary,
        ]);
    }
}
