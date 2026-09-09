<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An exception that carries an HTTP status. The exception handler renders the
 * matching error page (production) or a debug page (local).
 */
class HttpException extends RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, $message);
    }

    public static function methodNotAllowed(array $allowed, string $message = 'Method Not Allowed'): self
    {
        return new self(405, $message, ['Allow' => implode(', ', $allowed)]);
    }

    public static function tooManyRequests(int $retryAfter, string $message = 'Too Many Requests'): self
    {
        return new self(429, $message, ['Retry-After' => (string) $retryAfter]);
    }

    public static function pageExpired(string $message = 'Page Expired'): self
    {
        return new self(419, $message);
    }

    public static function conflict(string $message = 'Conflict'): self
    {
        return new self(409, $message);
    }

    public static function serviceUnavailable(string $message = 'Service Unavailable'): self
    {
        return new self(503, $message, ['Retry-After' => '120']);
    }
}
