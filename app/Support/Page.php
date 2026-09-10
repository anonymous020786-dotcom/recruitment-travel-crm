<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A page of list results plus the metadata a view needs to render pagination.
 *
 * @template T
 */
final class Page
{
    /** @param list<T> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?string $nextCursor = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function pageCount(): int
    {
        return $this->perPage > 0 ? (int) max(1, ceil($this->total / $this->perPage)) : 1;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
