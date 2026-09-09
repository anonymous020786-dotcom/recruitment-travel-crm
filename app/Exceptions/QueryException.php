<?php

declare(strict_types=1);

namespace App\Exceptions;

use PDOException;
use RuntimeException;

/**
 * Wraps a PDOException so the original SQL/bindings are available for logging
 * but never surface to the user. The message is deliberately generic in
 * production; the SQL + params live only in logs.
 */
final class QueryException extends RuntimeException
{
    /** @param array<int|string,mixed> $bindings */
    public function __construct(
        private readonly string $sql,
        private readonly array $bindings,
        PDOException $previous,
    ) {
        $sqlState = $previous->errorInfo[0] ?? $previous->getCode();
        parent::__construct(
            sprintf('Database error [%s] while executing a statement.', (string) $sqlState),
            is_int($previous->getCode()) ? $previous->getCode() : 0,
            $previous,
        );
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /** @return array<int|string,mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    public function sqlState(): ?string
    {
        $prev = $this->getPrevious();
        return $prev instanceof PDOException ? ($prev->errorInfo[0] ?? null) : null;
    }

    public function isDuplicateKey(): bool
    {
        return $this->sqlState() === '23000';
    }

    public function isDeadlock(): bool
    {
        $prev = $this->getPrevious();
        $driverCode = $prev instanceof PDOException ? ($prev->errorInfo[1] ?? null) : null;

        return in_array($driverCode, [1213, 1205], true); // deadlock, lock wait timeout
    }
}
