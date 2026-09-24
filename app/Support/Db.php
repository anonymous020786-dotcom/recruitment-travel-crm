<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\QueryException;
use Closure;
use DateTimeInterface;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper. The ONLY way the application talks to MySQL/MariaDB.
 *
 * Rules enforced by shape:
 *   - Every query goes through prepare()/execute() with bound parameters.
 *   - There is no method that accepts an interpolated value list — callers pass
 *     SQL with placeholders + a bindings array. Identifiers are never bound;
 *     repositories use allowlists for sort columns etc.
 *   - transaction() wraps a closure; nested calls use SAVEPOINTs; deadlocks
 *     retry up to $attempts.
 *
 * Bindings: an associative array binds by name (:key); a list binds positionally
 * (?). Values are normalised: bool -> int, DateTimeInterface -> 'Y-m-d H:i:s'
 * (UTC), null stays null, everything else is bound as-is with an inferred type.
 */
final class Db
{
    private ?PDO $pdo = null;
    private int $transactionLevel = 0;

    /** @var list<Closure> */
    private array $queryListeners = [];

    /** @param array<string,mixed> $config connection config (config('database.connections.mysql')) */
    public function __construct(
        private readonly array $config,
        private readonly ?Logger $logger = null,
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = (string) ($this->config['driver'] ?? 'mysql');

        if ($driver === 'sqlite') {
            $this->pdo = new PDO('sqlite:' . ($this->config['database'] ?? ':memory:'));
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            return $this->pdo;
        }

        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $driver,
            (string) ($this->config['host'] ?? '127.0.0.1'),
            (int) ($this->config['port'] ?? 3306),
            (string) ($this->config['database'] ?? ''),
            (string) ($this->config['charset'] ?? 'utf8mb4'),
        );

        try {
            $pdo = new PDO(
                $dsn,
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                (array) ($this->config['options'] ?? []),
            );
        } catch (PDOException $e) {
            $this->log('critical', 'Database connection failed', ['dsn_host' => $this->config['host'] ?? null, 'exception' => $e]);
            throw new QueryException('[connect]', [], $e);
        }

        $timezone = (string) ($this->config['timezone'] ?? '+00:00');
        $collation = (string) ($this->config['collation'] ?? 'utf8mb4_unicode_ci');
        $pdo->exec("SET time_zone = '{$timezone}'");
        $pdo->exec("SET NAMES {$this->config['charset']} COLLATE {$collation}");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");

        return $this->pdo = $pdo;
    }

    // ---- Reads --------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static fn (PDOStatement $s) => $s->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings, static fn (PDOStatement $s) => $s->fetch());

        return $row === false ? null : $row;
    }

    public function selectValue(string $sql, array $bindings = [], mixed $default = null): mixed
    {
        $row = $this->selectOne($sql, $bindings);
        if ($row === null) {
            return $default;
        }

        return array_values($row)[0] ?? $default;
    }

    /** @return \Generator<int,array<string,mixed>> row-by-row cursor for large exports */
    public function cursor(string $sql, array $bindings = []): \Generator
    {
        $statement = $this->prepared($sql, $bindings);
        while (($row = $statement->fetch()) !== false) {
            yield $row;
        }
    }

    public function exists(string $sql, array $bindings = []): bool
    {
        return $this->selectValue("SELECT EXISTS({$sql})", $bindings) ? true : false;
    }

    // ---- Writes ------------------------------------------------------------

    /** @return string last insert id */
    public function insert(string $sql, array $bindings = []): string
    {
        $this->run($sql, $bindings, static fn (PDOStatement $s) => $s->rowCount());

        return $this->pdo()->lastInsertId();
    }

    public function affectingStatement(string $sql, array $bindings = []): int
    {
        return (int) $this->run($sql, $bindings, static fn (PDOStatement $s) => $s->rowCount());
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        $this->run($sql, $bindings, static fn () => true);

        return true;
    }

    /** Raw DDL / multi-statement scripts (migrations only). No bindings. */
    public function unprepared(string $sql): bool
    {
        $this->fireListeners($sql, [], 0.0);

        try {
            return $this->pdo()->exec($sql) !== false;
        } catch (PDOException $e) {
            $this->log('error', 'Unprepared statement failed', ['exception' => $e]);
            throw new QueryException($sql, [], $e);
        }
    }

    // ---- Convenience row helpers (seeders, audit, simple services) --------

    /** @param array<string,mixed> $data @return string last insert id */
    public function insertRow(string $table, array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->wrapTable($table),
            implode(', ', array_map([$this, 'wrapColumn'], $columns)),
            implode(', ', $placeholders),
        );

        return $this->insert($sql, $data);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where equality conditions (ANDed)
     */
    public function updateRow(string $table, array $data, array $where): int
    {
        $set = [];
        $bind = [];
        foreach ($data as $col => $val) {
            $set[] = $this->wrapColumn($col) . ' = :set_' . $col;
            $bind['set_' . $col] = $val;
        }

        $conds = [];
        foreach ($where as $col => $val) {
            $conds[] = $this->wrapColumn($col) . ' = :w_' . $col;
            $bind['w_' . $col] = $val;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->wrapTable($table),
            implode(', ', $set),
            implode(' AND ', $conds) ?: '1 = 0',
        );

        return $this->affectingStatement($sql, $bind);
    }

    // ---- Transactions -----------------------------------------------------

    /**
     * @template T
     * @param Closure():T $callback
     * @return T
     */
    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback();
                $this->commit();

                return $result;
            } catch (QueryException $e) {
                $this->rollBack();

                if ($e->isDeadlock() && $attempt < $attempts) {
                    usleep(random_int(20_000, 120_000));
                    continue;
                }

                throw $e;
            } catch (Throwable $e) {
                $this->rollBack();
                throw $e;
            }
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT trans' . ($this->transactionLevel + 1));
        }

        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 1) {
            $this->pdo()->commit();
        }

        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel <= 1) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            $this->transactionLevel = 0;

            return;
        }

        $this->pdo()->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionLevel);
        $this->transactionLevel--;
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /** Close the connection; the next query opens a fresh one. Lets long-lived callers (the test suite) hand connections back. */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
    }

    // ---- Listeners (used by tests / query log) --------------------------

    public function listen(Closure $listener): void
    {
        $this->queryListeners[] = $listener;
    }

    // ---- Internals ------------------------------------------------------

    private function run(string $sql, array $bindings, Closure $handle): mixed
    {
        $start = microtime(true);
        $statement = $this->prepared($sql, $bindings);
        $result = $handle($statement);
        $this->fireListeners($sql, $bindings, (microtime(true) - $start) * 1000);

        return $result;
    }

    private function prepared(string $sql, array $bindings): PDOStatement
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            $this->log('error', 'Query failed: {sql}', ['sql' => $sql, 'exception' => $e]);
            throw new QueryException($sql, $bindings, $e);
        }
    }

    private function bindValues(PDOStatement $statement, array $bindings): void
    {
        $isList = array_is_list($bindings);

        foreach ($bindings as $key => $value) {
            $param = $isList ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);

            [$value, $type] = $this->normalize($value);
            $statement->bindValue($param, $value, $type);
        }
    }

    /** @return array{0:mixed,1:int} */
    private function normalize(mixed $value): array
    {
        if (is_bool($value)) {
            return [$value ? 1 : 0, PDO::PARAM_INT];
        }
        if ($value === null) {
            return [null, PDO::PARAM_NULL];
        }
        if (is_int($value)) {
            return [$value, PDO::PARAM_INT];
        }
        if ($value instanceof DateTimeInterface) {
            return [$value->format('Y-m-d H:i:s'), PDO::PARAM_STR];
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('NaN and infinite numbers cannot be stored.');
            }

            // The shortest text that reads back as exactly this float. (It used to be sprintf('%.8F'), which silently
            // cut everything past 8 decimals: -5.5E-10 was stored as -0.)
            return [var_export($value, true), PDO::PARAM_STR];
        }

        return [(string) $value, PDO::PARAM_STR];
    }

    private function fireListeners(string $sql, array $bindings, float $ms): void
    {
        foreach ($this->queryListeners as $listener) {
            $listener($sql, $bindings, $ms);
        }
    }

    private function wrapTable(string $table): string
    {
        return $this->wrapColumn($table);
    }

    private function wrapColumn(string $name): string
    {
        // Identifiers come from repositories/seeders (never user input). Still,
        // reject anything that is not a plain identifier or dotted identifier.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $name)) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$name}");
        }

        return implode('.', array_map(static fn ($p) => '`' . $p . '`', explode('.', $name)));
    }

    private function log(string $level, string $message, array $context): void
    {
        $this->logger?->log($level, $message, $context);
    }
}
