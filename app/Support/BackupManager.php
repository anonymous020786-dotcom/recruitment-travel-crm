<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Takes the daily backup (database dump + uploaded documents), proves it is readable, and prunes old files.
 *
 *   database   `db-<date>-<time>.sql.gz`, one consistent dump per run. Retention (docs/00-ARCHITECTURE.md §14):
 *              the newest 7 days, plus the newest 4 Sundays, plus the newest 3 first-of-the-month days.
 *   documents  `files-<date>-<time>-full.tar.gz` (everything, at most weekly) and `…-inc.tar.gz` (only files changed
 *              since the previous archive). Incrementals only make sense as a chain, so retention keeps whole chains:
 *              the 4 newest fulls, and every incremental newer than the second-newest full.
 *
 * Files go to `storage/private/backups` (never web-reachable) — copy them off-site as well: a backup that only lives
 * in the account it protects is not a backup.
 */
final class BackupManager
{
    public const DB_PATTERN = '/^db-(\d{8})-(\d{6})\.sql\.gz$/';
    public const FILES_PATTERN = '/^files-(\d{8})-(\d{6})-(full|inc)\.tar\.gz$/';
    /** A new full documents archive is taken when the newest one is at least this old. */
    public const FULL_EVERY_DAYS = 7;

    /** @param array<string,mixed> $dbConfig */
    public function __construct(
        private readonly string $backupDir,
        private readonly array $dbConfig,
        private readonly string $documentsDir,
        private readonly ?Logger $logger = null,
        private readonly int $maxFilesBytes = 2 * 1024 ** 3,
    ) {
    }

    /**
     * @return array{db:array<string,mixed>,files:?array<string,mixed>,pruned:list<string>}
     */
    public function run(bool $withFiles = true, bool $forceFull = false, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->ensureDir();
        $stamp = $now->format('Ymd-His');

        // ---- database ----
        $final = "{$this->backupDir}/db-{$stamp}.sql.gz";
        $partial = $final . '.partial';
        try {
            $dump = (new DbBackup($this->dbConfig))->dump($partial);
            $check = DbBackup::verify($partial);
            if ($check['rows'] !== $dump['rows'] || $check['tables'] !== $dump['tables']) {
                throw new RuntimeException('Backup verification disagrees with what was dumped.');
            }
            if (!rename($partial, $final)) {
                throw new RuntimeException('Could not finalise the backup file.');
            }
            @chmod($final, 0600);
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        }
        $db = ['file' => basename($final)] + $dump;
        $this->logger?->info('backup: database dumped ({tables} tables, {rows} rows, {bytes} bytes)', $dump);

        // ---- documents ----
        $files = $withFiles ? $this->backupFiles($stamp, $forceFull, $now) : null;

        return ['db' => $db, 'files' => $files, 'pruned' => $this->prune()];
    }

    /**
     * @return array{file:string,files:int,bytes:int,kind:string}|null null when nothing changed (or it was skipped)
     */
    private function backupFiles(string $stamp, bool $forceFull, \DateTimeImmutable $now): ?array
    {
        if (!class_exists(\PharData::class)) {
            $this->logger?->warning('backup: the phar extension is missing, documents were not archived');

            return null;
        }
        if (!is_dir($this->documentsDir)) {
            return null;
        }

        $existing = $this->listing(self::FILES_PATTERN);
        $lastAny = $existing[0]['at'] ?? null;
        $lastFull = null;
        foreach ($existing as $f) {
            if ($f['kind'] === 'full') {
                $lastFull = $f['at'];
                break;
            }
        }
        $full = $forceFull || $lastFull === null || $lastFull <= $now->modify('-' . self::FULL_EVERY_DAYS . ' days');
        // Incremental: everything changed since the previous archive began (5 minutes of overlap, so a file that was
        // being written while that archive ran is not missed).
        $since = $full || $lastAny === null ? null : $lastAny->modify('-5 minutes');

        $picked = [];
        $total = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->documentsDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $name = $file->getFilename();
            if (!$file->isFile() || $name === '.gitkeep' || $name === '.htaccess') {
                continue;
            }
            if ($since === null || $file->getMTime() >= $since->getTimestamp()) {
                $picked[str_replace('\\', '/', substr($file->getPathname(), strlen($this->documentsDir) + 1))] = $file->getPathname();
                $total += (int) $file->getSize();
            }
        }
        if ($picked === []) {
            return null;
        }
        if ($total > $this->maxFilesBytes) {
            $this->logger?->error('backup: documents ({mb} MB) exceed the archive limit — use the host\'s file backup or rsync for uploads', ['mb' => intdiv($total, 1048576)]);

            throw new RuntimeException('Documents are too large to archive from PHP (' . intdiv($total, 1048576) . ' MB); back them up another way.');
        }

        $kind = $full ? 'full' : 'inc';
        $tar = "{$this->backupDir}/files-{$stamp}-{$kind}.tar";
        $gz = $tar . '.gz';
        try {
            $archive = new \PharData($tar);
            foreach ($picked as $relative => $absolute) {
                $archive->addFile($absolute, $relative);
            }
            unset($archive);                                  // flushes the tar to disk

            // Gzip it ourselves. PharData::compress() cannot be trusted: on PHP 8.2 / Windows it produced a
            // "valid" .tar.gz that held the file names but none of the file contents.
            $in = fopen($tar, 'rb');
            $out = gzopen($gz, 'wb6');
            if ($in === false || $out === false) {
                throw new RuntimeException('Cannot write the documents archive.');
            }
            while (!feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }
            fclose($in);
            gzclose($out);
            @unlink($tar);

            $this->verifyArchive($gz, $picked);
        } catch (\Throwable $e) {
            @unlink($tar);
            @unlink($gz);
            throw $e;
        }
        @chmod($gz, 0600);
        $this->logger?->info('backup: {n} document file(s) archived ({kind})', ['n' => count($picked), 'kind' => $kind]);

        return ['file' => basename($gz), 'files' => count($picked), 'bytes' => (int) filesize($gz), 'kind' => $kind];
    }

    /**
     * Reads the finished archive back and proves every file is in it with exactly its original bytes.
     *
     * @param array<string,string> $picked relative path => absolute source path
     */
    private function verifyArchive(string $gz, array $picked): void
    {
        $archive = new \PharData($gz);
        $seen = 0;
        foreach (new \RecursiveIteratorIterator($archive) as $entry) {
            /** @var \PharFileInfo $entry */
            $relative = substr(str_replace('\\', '/', $entry->getPathname()), strpos(str_replace('\\', '/', $entry->getPathname()), '.tar.gz/') + 8);
            $source = $picked[$relative] ?? null;
            if ($source === null) {
                throw new RuntimeException("Documents archive holds an unexpected entry: {$relative}");
            }
            if (hash('sha256', (string) file_get_contents($entry->getPathname())) !== hash_file('sha256', $source)) {
                throw new RuntimeException("Documents archive entry {$relative} does not match its source file.");
            }
            $seen++;
        }
        if ($seen !== count($picked)) {
            throw new RuntimeException("Documents archive holds {$seen} files, expected " . count($picked));
        }
    }

    /**
     * Deletes backups the retention policy no longer needs. Returns the deleted file names.
     *
     * @return list<string>
     */
    public function prune(): array
    {
        $deleted = [];

        $db = $this->listing(self::DB_PATTERN);
        $keep = self::retain(array_map(static fn (array $f): \DateTimeImmutable => $f['at'], $db));
        foreach ($db as $f) {
            if (!isset($keep[$f['at']->format('Ymd-His')]) && @unlink($f['path'])) {
                $deleted[] = $f['name'];
            }
        }

        $files = $this->listing(self::FILES_PATTERN);
        $keepFiles = self::retainFiles(array_map(static fn (array $f): array => ['at' => $f['at'], 'kind' => (string) $f['kind']], $files));
        foreach ($files as $f) {
            if (!isset($keepFiles[$f['at']->format('Ymd-His')]) && @unlink($f['path'])) {
                $deleted[] = $f['name'];
            }
        }

        return $deleted;
    }

    /**
     * Database retention: for each calendar day keep only its newest backup, then keep the newest 7 days, the newest
     * 4 Sundays and the newest 3 first-of-the-month days. Pure function, unit-tested.
     *
     * @param list<\DateTimeImmutable> $times backup times (UTC)
     * @return array<string,true> set of kept timestamps formatted `Ymd-His`
     */
    public static function retain(array $times): array
    {
        usort($times, static fn ($a, $b): int => $b <=> $a);   // newest first

        $perDay = [];
        foreach ($times as $t) {
            $perDay[$t->format('Ymd')] ??= $t;                 // newest of each day
        }
        $days = array_values($perDay);

        $keep = [];
        foreach (array_slice($days, 0, 7) as $t) {
            $keep[$t->format('Ymd-His')] = true;
        }
        foreach (array_slice(array_values(array_filter($days, static fn ($t): bool => $t->format('w') === '0')), 0, 4) as $t) {
            $keep[$t->format('Ymd-His')] = true;
        }
        foreach (array_slice(array_values(array_filter($days, static fn ($t): bool => $t->format('j') === '1')), 0, 3) as $t) {
            $keep[$t->format('Ymd-His')] = true;
        }

        return $keep;
    }

    /**
     * Documents retention keeps restorable chains: the 4 newest full archives, plus every incremental newer than the
     * second-newest full (so the two most recent chains are complete). Older incrementals are useless without their
     * full and are dropped with it; older fulls stand alone as weekly snapshots. Pure function, unit-tested.
     *
     * @param list<array{at:\DateTimeImmutable,kind:string}> $archives
     * @return array<string,true> kept timestamps formatted `Ymd-His`
     */
    public static function retainFiles(array $archives): array
    {
        usort($archives, static fn ($a, $b): int => $b['at'] <=> $a['at']);

        $fulls = array_values(array_filter($archives, static fn (array $a): bool => $a['kind'] === 'full'));
        $keep = [];
        foreach (array_slice($fulls, 0, 4) as $a) {
            $keep[$a['at']->format('Ymd-His')] = true;
        }
        $chainStart = $fulls[1]['at'] ?? null;                  // second-newest full
        foreach ($archives as $a) {
            if ($a['kind'] === 'inc' && ($chainStart === null || $a['at'] > $chainStart)) {
                $keep[$a['at']->format('Ymd-His')] = true;
            }
        }

        return $keep;
    }

    /** @return list<array{name:string,path:string,at:\DateTimeImmutable,kind:?string}> newest first */
    public function listing(string $pattern): array
    {
        $out = [];
        foreach (glob($this->backupDir . '/*') ?: [] as $path) {
            if (preg_match($pattern, basename($path), $m) === 1) {
                $at = \DateTimeImmutable::createFromFormat('YmdHis', $m[1] . $m[2], new \DateTimeZone('UTC'));
                if ($at !== false) {
                    $out[] = ['name' => basename($path), 'path' => $path, 'at' => $at, 'kind' => $m[3] ?? null];
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $out;
    }

    /** The newest database dump on disk, or null. */
    public function latestDb(): ?string
    {
        return $this->listing(self::DB_PATTERN)[0]['path'] ?? null;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0700, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException("Cannot create the backup directory {$this->backupDir}");
        }
    }
}
