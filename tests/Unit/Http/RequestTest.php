<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private function make(array $server = [], array $post = [], string $body = '', array $query = []): Request
    {
        return new Request($query, $post, [], [], $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], $body);
    }

    public function test_method_override_only_from_post_on_post(): void
    {
        $r = $this->make(['REQUEST_METHOD' => 'POST'], ['_method' => 'PATCH']);
        self::assertSame('PATCH', $r->method());
        self::assertSame('POST', $r->realMethod());

        $r2 = $this->make(['REQUEST_METHOD' => 'GET'], ['_method' => 'DELETE']);
        self::assertSame('GET', $r2->method(), 'override must not apply to GET');
    }

    public function test_path_normalisation(): void
    {
        self::assertSame('/leads/42', $this->make(['REQUEST_URI' => '/leads/42/?x=1'])->path());
        self::assertSame('/', $this->make(['REQUEST_URI' => '/'])->path());
    }

    public function test_json_body_parsing_and_input_precedence(): void
    {
        $r = $this->make(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'],
            [],
            '{"name":"Asha","age":30}',
        );
        self::assertTrue($r->isJson());
        self::assertSame('Asha', $r->input('name'));
        self::assertSame(30, $r->integer('age'));
    }

    public function test_wants_json(): void
    {
        self::assertTrue($this->make(['HTTP_ACCEPT' => 'application/json'])->wantsJson());
        self::assertTrue($this->make(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])->wantsJson());
        self::assertFalse($this->make(['HTTP_ACCEPT' => 'text/html'])->wantsJson());
    }

    public function test_ip_ignores_forwarded_header_without_trusted_proxy(): void
    {
        $r = new Request([], [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/',
        ], '');
        self::assertSame('203.0.113.9', $r->ip());
    }

    public function test_ip_honours_forwarded_header_from_trusted_proxy(): void
    {
        $r = new Request([], [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1',
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/',
        ], '', ['10.0.0.1']);
        self::assertSame('198.51.100.7', $r->ip());
    }

    public function test_scheme_detection(): void
    {
        self::assertTrue($this->make(['HTTPS' => 'on'])->isSecure());
        self::assertFalse($this->make(['HTTPS' => 'off'])->isSecure());
    }
}
