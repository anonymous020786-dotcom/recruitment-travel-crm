<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an optimistic-lock check fails: the record's `record_version`
 * changed since the client loaded it. Rendered as 409 with a reload prompt.
 */
final class StaleRecordException extends RuntimeException
{
    public function __construct(
        private readonly string $entity,
        private readonly int|string $id,
        string $message = 'This record was changed by someone else since you opened it. Reload and reapply your changes.',
    ) {
        parent::__construct($message);
    }

    public function entity(): string
    {
        return $this->entity;
    }

    public function id(): int|string
    {
        return $this->id;
    }
}
