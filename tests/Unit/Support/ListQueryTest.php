<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Http\Request;
use App\Support\ListQuery;
use PHPUnit\Framework\TestCase;

final class ListQueryTest extends TestCase
{
    private function req(array $query): Request
    {
        return new Request($query, [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x'], '');
    }

    private const SORTS = ['created_at' => 'l.created_at', 'name' => 'l.name'];

    public function test_sort_falls_back_when_not_allowlisted(): void
    {
        $q = ListQuery::fromRequest($this->req(['sort' => 'password']), self::SORTS);
        self::assertSame('created_at', $q->sort);
    }

    public function test_sort_accepted_when_allowlisted(): void
    {
        $q = ListQuery::fromRequest($this->req(['sort' => 'name', 'dir' => 'asc']), self::SORTS);
        self::assertSame('name', $q->sort);
        self::assertSame('asc', $q->direction);
    }

    public function test_direction_defaults_and_sanitises(): void
    {
        self::assertSame('desc', ListQuery::fromRequest($this->req(['dir' => 'sideways']), self::SORTS)->direction);
    }

    public function test_per_page_clamped_to_options(): void
    {
        self::assertSame(50, ListQuery::fromRequest($this->req(['per_page' => '50']), self::SORTS)->perPage);
        self::assertSame(25, ListQuery::fromRequest($this->req(['per_page' => '9999']), self::SORTS)->perPage);
        self::assertSame(25, ListQuery::fromRequest($this->req(['per_page' => '7']), self::SORTS)->perPage);
    }

    public function test_page_minimum_is_one(): void
    {
        self::assertSame(1, ListQuery::fromRequest($this->req(['page' => '-5']), self::SORTS)->page);
        self::assertSame(3, ListQuery::fromRequest($this->req(['page' => '3']), self::SORTS)->page);
    }

    public function test_filters_read_only_declared_keys(): void
    {
        $q = ListQuery::fromRequest(
            $this->req(['status' => 'new', 'evil' => 'x', 'priority' => ' high ']),
            self::SORTS,
            ['status', 'priority'],
        );
        self::assertSame('new', $q->filter('status'));
        self::assertSame('high', $q->filter('priority'));
        self::assertNull($q->filter('evil'));
    }

    public function test_search_is_trimmed_and_length_capped(): void
    {
        $q = ListQuery::fromRequest($this->req(['q' => '  ' . str_repeat('a', 300)]), self::SORTS);
        self::assertSame(120, mb_strlen($q->search));
    }

    public function test_offset_calculation(): void
    {
        $q = ListQuery::fromRequest($this->req(['page' => '3', 'per_page' => '50']), self::SORTS);
        self::assertSame(100, $q->offset());
    }

    public function test_to_query_array_round_trips_state(): void
    {
        $q = ListQuery::fromRequest(
            $this->req(['q' => 'asha', 'status' => 'new', 'sort' => 'name', 'dir' => 'asc', 'per_page' => '50']),
            self::SORTS,
            ['status'],
        );
        $arr = $q->toQueryArray();
        self::assertSame('asha', $arr['q']);
        self::assertSame('new', $arr['status']);
        self::assertSame('name', $arr['sort']);
        self::assertSame(50, $arr['per_page']);
    }
}
