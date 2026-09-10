<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Request;

/**
 * Normalised list-screen parameters: page / per-page / sort / direction /
 * free-text search / arbitrary filters. Sort keys and per-page sizes are
 * clamped against allowlists supplied by the repository — user input never
 * reaches `ORDER BY` or `LIMIT` unfiltered.
 */
final class ListQuery
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /**
     * @param array<string,string> $filters
     */
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $sort,
        public readonly string $direction,
        public readonly string $search,
        public readonly array $filters,
        public readonly ?string $cursor,
    ) {
    }

    /**
     * @param array<string,string> $sortAllowlist  public key => "table.column"
     * @param list<string> $filterKeys  filter names to read from the request
     */
    public static function fromRequest(
        Request $request,
        array $sortAllowlist,
        array $filterKeys = [],
        string $defaultSort = '',
        string $defaultDirection = 'desc',
    ): self {
        $defaultSort = $defaultSort !== '' ? $defaultSort : (array_key_first($sortAllowlist) ?? 'id');

        $sort = (string) $request->query('sort', $defaultSort);
        if (!array_key_exists($sort, $sortAllowlist)) {
            $sort = $defaultSort;
        }

        $direction = strtolower((string) $request->query('dir', $defaultDirection)) === 'asc' ? 'asc' : 'desc';

        $perPage = (int) $request->query('per_page', (string) self::PER_PAGE_OPTIONS[0]);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }

        $page = max(1, (int) $request->query('page', '1'));

        $filters = [];
        foreach ($filterKeys as $key) {
            $value = $request->query($key);
            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = trim($value);
            }
        }

        $search = trim((string) $request->query('q', ''));
        if (mb_strlen($search) > 120) {
            $search = mb_substr($search, 0, 120);
        }

        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        return new self($page, $perPage, $sort, $direction, $search, $filters, $cursor);
    }

    /** For building an empty query in tests / defaults. */
    public static function of(array $overrides = []): self
    {
        return new self(
            $overrides['page'] ?? 1,
            $overrides['perPage'] ?? 25,
            $overrides['sort'] ?? 'id',
            $overrides['direction'] ?? 'desc',
            $overrides['search'] ?? '',
            $overrides['filters'] ?? [],
            $overrides['cursor'] ?? null,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function filter(string $key, ?string $default = null): ?string
    {
        return $this->filters[$key] ?? $default;
    }

    public function hasSearch(): bool
    {
        return $this->search !== '';
    }

    /** @return array<string,string|int> query-string params for pagination links */
    public function toQueryArray(): array
    {
        $out = $this->filters;
        if ($this->search !== '') {
            $out['q'] = $this->search;
        }
        if ($this->sort !== '') {
            $out['sort'] = $this->sort;
            $out['dir'] = $this->direction;
        }
        if ($this->perPage !== self::PER_PAGE_OPTIONS[0]) {
            $out['per_page'] = $this->perPage;
        }

        return $out;
    }
}
