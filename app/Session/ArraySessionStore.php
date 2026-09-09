<?php

declare(strict_types=1);

namespace App\Session;

/** In-memory session store for tests. */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string,array{data:array<string,mixed>,touched:int}> */
    public array $sessions = [];

    public function read(string $id): array
    {
        return $this->sessions[$id]['data'] ?? [];
    }

    public function write(string $id, array $data, array $meta = []): void
    {
        $this->sessions[$id] = ['data' => $data, 'touched' => time()];
    }

    public function destroy(string $id): void
    {
        unset($this->sessions[$id]);
    }

    public function gc(int $maxLifetimeSeconds): int
    {
        $cutoff = time() - $maxLifetimeSeconds;
        $removed = 0;
        foreach ($this->sessions as $id => $meta) {
            if ($meta['touched'] < $cutoff) {
                unset($this->sessions[$id]);
                $removed++;
            }
        }

        return $removed;
    }
}
