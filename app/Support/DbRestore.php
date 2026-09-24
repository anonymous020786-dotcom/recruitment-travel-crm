<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Replays a DbBackup dump into a database. Every statement in the dump is on one line, so no SQL parser is needed.
 *
 * Refuses to overwrite the database the application is configured to use unless the caller says so explicitly —
 * the routine restore *drill* goes into a scratch database, and a real disaster restore is a deliberate act.
 */
final class DbRestore
{
    /** @param array<string,mixed> $targetConfig connection settings of the database to restore INTO */
    public function __construct(private readonly array $targetConfig)
    {
    }

    /**
     * @param string $liveDatabase the application's own database name
     * @return array{statements:int,tables:int,rows:int}
     */
    public function restore(string $gzPath, string $liveDatabase, bool $allowLive = false): array
    {
        $target = (string) ($this->targetConfig['database'] ?? '');
        if ($target === '') {
            throw new RuntimeException('No target database given.');
        }
        if ($target === $liveDatabase && !$allowLive) {
            throw new RuntimeException("Refusing to restore over the live database '{$target}' without explicit confirmation.");
        }

        // Prove the file is whole before touching anything.
        DbBackup::verify($gzPath);

        $c = $this->targetConfig;
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? '127.0.0.1', (int) ($c['port'] ?? 3306), $target),
            (string) ($c['username'] ?? ''),
            (string) ($c['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true],
        );

        $in = gzopen($gzPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open the backup file.');
        }

        $statements = $tables = 0;
        try {
            while (($line = gzgets($in)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                $pdo->exec($line);
                $statements++;
                if (str_starts_with($line, 'CREATE TABLE ')) {
                    $tables++;
                }
            }
        } finally {
            gzclose($in);
        }

        $rows = 0;
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t) {
            $rows += (int) $pdo->query('SELECT COUNT(*) FROM `' . Sql::identifier((string) $t[0]) . '`')->fetchColumn();
        }

        return ['statements' => $statements, 'tables' => $tables, 'rows' => $rows];
    }
}
