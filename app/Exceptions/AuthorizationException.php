<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by policies / the authorization gate when the acting user may not
 * perform an action. Rendered as 403. The message is safe for display but
 * deliberately non-specific about why (no record-existence disclosure).
 */
final class AuthorizationException extends RuntimeException
{
    public function __construct(
        string $message = 'You do not have permission to perform this action.',
        private readonly ?string $permission = null,
    ) {
        parent::__construct($message);
    }

    public function permission(): ?string
    {
        return $this->permission;
    }

    public static function forPermission(string $permission): self
    {
        return new self(permission: $permission);
    }
}
