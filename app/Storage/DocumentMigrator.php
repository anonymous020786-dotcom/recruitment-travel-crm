<?php

declare(strict_types=1);

namespace App\Storage;

use App\Audit\AuditService;
use App\Models\User;
use App\Support\Application;
use App\Support\Db;
use App\Support\Logger;

/**
 * Moves documents that still sit on the server's private disk into the active bucket, a few at a time (so a shared host's time
 * limit is never hit). Each move is verified before anything is removed: the upload carries its real SHA-256, the object is read
 * back with a HEAD and its size compared, and only then is the row switched to the new disk and the local copy deleted. A document
 * whose file is missing or whose upload fails is left exactly as it was and counted, so the run can simply be repeated.
 */
final class DocumentMigrator
{
    public function __construct(
        private readonly Db $db,
        private readonly ObjectStorage $objects,
        private readonly Application $app,
        private readonly Logger $logger,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array{pending:int,bytes:int} documents still on the server disk */
    public function pending(): array
    {
        $r = $this->db->selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS b FROM candidate_documents WHERE storage_disk = :d', ['d' => ObjectStorage::LOCAL]) ?? [];

        return ['pending' => (int) ($r['n'] ?? 0), 'bytes' => (int) ($r['b'] ?? 0)];
    }

    /**
     * @return array{disk:string,moved:int,failed:int,missing:int,bytes:int,remaining:int,dry_run:bool}
     */
    public function moveBatch(int $limit = 25, bool $dryRun = false, ?User $actor = null): array
    {
        $disk = $this->objects->activeDisk();
        $result = ['disk' => $disk, 'moved' => 0, 'failed' => 0, 'missing' => 0, 'bytes' => 0, 'remaining' => 0, 'dry_run' => $dryRun];
        if (!ObjectStorage::isRemote($disk)) {
            return $result + ['error' => 'No bucket is configured and selected as the storage location.'];
        }

        $rows = $this->db->select(
            'SELECT id, storage_path, mime_type, sha256, size_bytes FROM candidate_documents WHERE storage_disk = :d ORDER BY id LIMIT ' . max(1, min($limit, 200)),
            ['d' => ObjectStorage::LOCAL],
        );
        foreach ($rows as $row) {
            $absolute = $this->app->basePath((string) $row['storage_path']);
            if (!is_file($absolute)) {
                $result['missing']++;
                continue;
            }
            if ($dryRun) {
                $result['moved']++;
                $result['bytes'] += (int) $row['size_bytes'];
                continue;
            }
            $stored = $this->objects->put($disk, (string) $row['storage_path'], $absolute, (string) $row['mime_type'], (string) $row['sha256']);
            $client = $stored ? $this->objects->client($disk)?->head(ObjectStorage::key((string) $row['storage_path'])) : null;
            if (!$stored || $client === null || $client['size'] !== (int) filesize($absolute)) {
                $result['failed']++;
                $this->logger->warning('document {id} was not moved to {disk}: upload or verification failed', ['id' => $row['id'], 'disk' => $disk]);
                continue;
            }
            $this->db->affectingStatement('UPDATE candidate_documents SET storage_disk = :d WHERE id = :id AND storage_disk = :old', ['d' => $disk, 'id' => $row['id'], 'old' => ObjectStorage::LOCAL]);
            @unlink($absolute);
            $result['moved']++;
            $result['bytes'] += (int) $row['size_bytes'];
        }
        $result['remaining'] = $this->pending()['pending'];
        if (!$dryRun && $result['moved'] > 0) {
            $this->audit->log('documents_moved_to_bucket', 'storage', 'storage', 0, null, ['disk' => $disk, 'moved' => $result['moved'], 'failed' => $result['failed']], null, $actor);
        }

        return $result;
    }
}
