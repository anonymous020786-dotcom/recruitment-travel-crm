<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Logical database backup in pure PHP (shared hosting often has no `mysqldump` and no `exec`).
 *
 * The dump is a gzip'd SQL text file that DbRestore — or plain `gunzip < file | mysql` — can replay:
 *
 *   - one consistent snapshot: everything is read inside START TRANSACTION WITH CONSISTENT SNAPSHOT, so tables that
 *     reference each other (invoices ↔ payments ↔ allocations) are captured at the same instant while the site keeps
 *     taking writes;
 *   - streamed row by row over an *unbuffered* connection, so memory stays flat however large a table is;
 *   - every statement sits on ONE line (PDO::quote escapes newlines), which is what lets the restorer replay it
 *     without a SQL parser, and lets `verify()` count what it read;
 *   - transient tables (sessions, rate limits, cron locks) keep their structure but not their rows;
 *   - a footer records the table and row totals: a file without it is a truncated dump and is rejected.
 */
final class DbBackup
{
    public const FOOTER = '-- dump complete';
    /** Tables whose rows are throw-away: only the structure is dumped. */
    public const STRUCTURE_ONLY = ['sessions', 'rate_limits', 'cron_locks'];
    private const BATCH_ROWS = 200;
    private const BATCH_BYTES = 262144;

    /** @param array<string,mixed> $dbConfig connection settings (config('database.connections.mysql')) */
    public function __construct(private readonly array $dbConfig)
    {
    }

    /**
     * @return array{tables:int,rows:int,bytes:int,database:string,per_table:array<string,int>}
     */
    public function dump(string $gzPath): array
    {
        $pdo = $this->connect();
        $database = (string) ($this->dbConfig['database'] ?? '');

        $out = gzopen($gzPath, 'wb6');
        if ($out === false) {
            throw new RuntimeException("Cannot write backup file {$gzPath}");
        }

        $tables = 0;
        $rows = 0;
        $perTable = [];

        try {
            $this->line($out, '-- CRM database backup');
            $this->line($out, '-- database: ' . $database);
            $this->line($out, '-- taken (UTC): ' . gmdate('Y-m-d H:i:s'));
            $this->line($out, "SET NAMES utf8mb4;");
            $this->line($out, "SET time_zone = '+00:00';");
            $this->line($out, 'SET FOREIGN_KEY_CHECKS = 0;');
            $this->line($out, 'SET UNIQUE_CHECKS = 0;');
            $this->line($out, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");

            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            foreach ($this->tables($pdo) as $table) {
                $tables++;
                $q = $this->ident($table);
                $create = (string) ($pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1] ?? '');
                if ($create === '') {
                    throw new RuntimeException("Could not read the definition of {$table}");
                }

                $this->line($out, "-- table {$table}");
                $this->line($out, "DROP TABLE IF EXISTS {$q};");
                $this->line($out, preg_replace('/\s*\R\s*/', ' ', $create) . ';');

                if (!in_array($table, self::STRUCTURE_ONLY, true)) {
                    $n = $this->dumpRows($pdo, $out, $table);
                    $rows += $n;
                    $perTable[$table] = $n;
                }
            }

            $pdo->exec('COMMIT');
            $this->line($out, 'SET FOREIGN_KEY_CHECKS = 1;');
            $this->line($out, self::FOOTER . " tables={$tables} rows={$rows}");
        } finally {
            gzclose($out);
        }

        return ['tables' => $tables, 'rows' => $rows, 'bytes' => (int) filesize($gzPath), 'database' => $database, 'per_table' => $perTable];
    }

    /**
     * Reads a finished dump end to end and proves it is whole: valid gzip, footer present, footer totals equal what
     * was actually read. Throws otherwise.
     *
     * @return array{tables:int,rows:int,statements:int}
     */
    public static function verify(string $gzPath, int $minBytes = 512): array
    {
        if (!is_file($gzPath) || filesize($gzPath) < $minBytes) {
            throw new RuntimeException('Backup file is missing or suspiciously small.');
        }
        $in = gzopen($gzPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Backup file cannot be opened as gzip.');
        }

        $tables = $rows = $statements = 0;
        $footer = null;
        try {
            while (($line = gzgets($in)) !== false) {
                $line = rtrim($line, "\r\n");
                if (str_starts_with($line, '-- table ')) {
                    $tables++;
                } elseif (str_starts_with($line, self::FOOTER)) {
                    $footer = $line;
                } elseif (str_starts_with($line, 'INSERT INTO ')) {
                    $rows += self::rowsInInsert($line);
                }
                if ($line !== '' && !str_starts_with($line, '--')) {
                    $statements++;
                }
            }
            if (!gzeof($in)) {
                throw new RuntimeException('Backup file is corrupt (gzip stream ended early).');
            }
        } finally {
            gzclose($in);
        }

        if ($footer === null) {
            throw new RuntimeException('Backup is truncated: the completion footer is missing.');
        }
        if (!preg_match('/tables=(\d+) rows=(\d+)/', $footer, $m) || (int) $m[1] !== $tables || (int) $m[2] !== $rows) {
            throw new RuntimeException("Backup footer ({$footer}) does not match the content read ({$tables} tables, {$rows} rows).");
        }

        return ['tables' => $tables, 'rows' => $rows, 'statements' => $statements];
    }

    // ---- internals -----------------------------------------------------------

    private function connect(): PDO
    {
        $c = $this->dbConfig;
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? '127.0.0.1', (int) ($c['port'] ?? 3306), $c['database'] ?? ''),
            (string) ($c['username'] ?? ''),
            (string) ($c['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,      // plain text protocol: lets the SELECT stream unbuffered
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ],
        );
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET NAMES utf8mb4');

        return $pdo;
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        $names = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) {
            $names[] = (string) $r[0];
        }
        sort($names);

        return $names;
    }

    /** @param resource $out */
    private function dumpRows(PDO $pdo, $out, string $table): int
    {
        $q = $this->ident($table);

        // Columns whose bytes are not text (VARBINARY ip addresses, BLOBs) are written as hex literals.
        $binary = [];
        $cols = $pdo->prepare('SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $cols->execute([$table]);
        foreach ($cols->fetchAll() as $c) {
            $binary[(string) $c['column_name']] = in_array(strtolower((string) $c['data_type']), ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bit'], true);
        }

        $stmt = $pdo->query("SELECT * FROM {$q}");
        $count = 0;
        $batch = [];
        $bytes = 0;
        $head = null;

        $flush = function () use (&$batch, &$bytes, &$head, $out): void {
            if ($batch !== []) {
                $this->line($out, $head . ' VALUES ' . implode(',', $batch) . ';');
                $batch = [];
                $bytes = 0;
            }
        };

        while (($row = $stmt->fetch()) !== false) {
            $head ??= "INSERT INTO {$q} (" . implode(',', array_map(fn ($k) => $this->ident((string) $k), array_keys($row))) . ')';
            $values = [];
            foreach ($row as $col => $v) {
                $values[] = $this->literal($pdo, $v, $binary[$col] ?? false);
            }
            $tuple = '(' . implode(',', $values) . ')';
            $batch[] = $tuple;
            $bytes += strlen($tuple);
            $count++;

            if (count($batch) >= self::BATCH_ROWS || $bytes >= self::BATCH_BYTES) {
                $flush();
            }
        }
        $flush();
        $stmt->closeCursor();

        return $count;
    }

    private function literal(PDO $pdo, mixed $v, bool $binary): string
    {
        return match (true) {
            $v === null => 'NULL',
            is_int($v) => (string) $v,
            is_bool($v) => $v ? '1' : '0',
            is_float($v) => sprintf('%.17g', $v),
            $binary => $v === '' ? "''" : '0x' . bin2hex((string) $v),
            default => (string) $pdo->quote((string) $v),
        };
    }

    private function ident(string $name): string
    {
        return '`' . Sql::identifier($name) . '`';
    }

    /** @param resource $out */
    private function line($out, string $sql): void
    {
        gzwrite($out, $sql . "\n");
    }

    /** Number of row tuples in one extended INSERT line (parsed with quote/escape awareness). */
    private static function rowsInInsert(string $line): int
    {
        $start = strpos($line, ' VALUES ');
        if ($start === false) {
            return 0;
        }
        $rows = 0;
        $depth = 0;
        $inStr = false;
        for ($i = $start + 8, $n = strlen($line); $i < $n; $i++) {
            $ch = $line[$i];
            if ($inStr) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === "'") {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === "'") {
                $inStr = true;
            } elseif ($ch === '(') {
                if ($depth++ === 0) {
                    $rows++;
                }
            } elseif ($ch === ')') {
                $depth--;
            }
        }

        return $rows;
    }
}
