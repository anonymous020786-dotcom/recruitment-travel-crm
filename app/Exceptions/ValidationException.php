<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when input validation fails. Rendered as 422 (JSON) or a re-rendered
 * form with old input + inline errors (web).
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string,list<string>> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data was invalid.',
    ) {
        parent::__construct($message);
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function first(): ?string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return null;
    }

    /** @param array<string,list<string>> $errors */
    public static function withErrors(array $errors): self
    {
        return new self($errors);
    }
}
