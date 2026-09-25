<?php

declare(strict_types=1);

namespace App\Storage;

use App\Integrations\Credentials;
use App\Support\Logger;

/**
 * Copies each night's backup files to the storage bucket (under `backups/`) so a lost or burnt-down server does not take the
 * backups with it, then removes remote backups older than the retention period (a lifecycle rule does the same on the bucket
 * side; doing it here as well keeps the bucket tidy even if the rules were never applied).
 *
 * Every copy is verified with a HEAD (size must match) and reported; a failure is logged and returned but never fails the local
 * backup, which has already succeeded by the time this runs. Only the backup files named by BackupManager are ever uploaded.
 */
final class OffsiteBackup
{
    public function __construct(
        private readonly ObjectStorage $objects,
        private readonly Credentials $credentials,
        private readonly Logger $logger,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function enabled(): bool
    {
        return ObjectStorage::isRemote($this->objects->activeDisk());
    }

    /**
     * @param array{db?:array<string,mixed>,files?:?array<string,mixed>} $result what BackupManager::run returned
     * @return array{uploaded:list<string>,failed:list<string>,pruned:int,skipped:bool}
     */
    public function push(string $backupDir, array $result): array
    {
        $out = ['uploaded' => [], 'failed' => [], 'pruned' => 0, 'skipped' => false];
        $client = $this->objects->client($this->objects->activeDisk());
        if ($client === null) {
            return ['skipped' => true] + $out;
        }

        foreach ([$result['db']['file'] ?? null, $result['files']['file'] ?? null] as $name) {
            if (!is_string($name) || preg_match('/^(db|files)-[0-9]{8}-[0-9]{6}[a-z.-]*\.(sql|tar)\.gz$/', $name) !== 1) {
                continue;
            }
            $path = rtrim($backupDir, '/\\') . '/' . $name;
            $put = is_file($path) ? $client->put('backups/' . $name, $path, 'application/gzip', ['sha256' => (string) hash_file('sha256', $path)]) : ['ok' => false];
            $head = $put['ok'] ? $client->head('backups/' . $name) : null;
            if ($put['ok'] && $head !== null && $head['size'] === (int) filesize($path)) {
                $out['uploaded'][] = $name;
                continue;
            }
            $out['failed'][] = $name;
            $this->logger->warning('off-site backup of {file} failed', ['file' => $name]);
        }

        $out['pruned'] = $this->prune($client);

        return $out;
    }

    /** Delete remote backups older than the retention period. Returns how many were removed. */
    public function prune(S3Client $client): int
    {
        $days = max(1, (int) ($this->credentials->get('storage', 'backup_retention_days') ?? 30));
        $cutoff = (($this->clock ?? static fn (): int => time())()) - $days * 86400;
        $removed = 0;
        $token = null;
        do {
            $page = $client->list('backups/', 1000, $token);
            foreach ($page['keys'] as $k) {
                $at = strtotime($k['modified']);
                if ($at !== false && $at < $cutoff && $client->delete($k['key'])) {
                    $removed++;
                }
            }
            $token = $page['truncated'] ? $page['next'] : null;
        } while ($token !== null);

        return $removed;
    }
}
