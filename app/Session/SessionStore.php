<?php

declare(strict_types=1);

namespace App\Session;

/**
 * Backing store for session payloads. Payload is an associative array of
 * JSON-serialisable values (no objects — keep session data simple).
 */
interface SessionStore
{
    /** @return array<string,mixed> */
    public function read(string $id): array;

    /** @param array<string,mixed> $data */
    public function write(string $id, array $data, array $meta = []): void;

    public function destroy(string $id): void;

    /** @return int rows removed */
    public function gc(int $maxLifetimeSeconds): int;
}
