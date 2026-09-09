<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_json_factory_sets_content_type_and_encodes(): void
    {
        $r = Response::json(['a' => 1], 201);
        self::assertSame(201, $r->getStatus());
        self::assertSame('application/json; charset=UTF-8', $r->getHeader('Content-Type'));
        self::assertSame('{"a":1}', $r->getBody());
    }

    public function test_redirect_blocks_external_by_default(): void
    {
        self::assertSame('/', Response::redirect('https://evil.example/phish')->getHeader('Location'));
        self::assertSame('/', Response::redirect('//evil.example')->getHeader('Location'));
        self::assertSame('/dashboard', Response::redirect('/dashboard')->getHeader('Location'));
    }

    public function test_redirect_allows_external_when_opted_in(): void
    {
        self::assertSame(
            'https://accounts.example/oauth',
            Response::redirect('https://accounts.example/oauth', 302, true)->getHeader('Location'),
        );
    }

    public function test_redirect_strips_header_injection(): void
    {
        $r = Response::redirect("/ok\r\nSet-Cookie: x=1");
        self::assertStringNotContainsString("\r", (string) $r->getHeader('Location'));
        self::assertStringNotContainsString("\n", (string) $r->getHeader('Location'));
    }

    public function test_header_names_are_normalised(): void
    {
        $r = Response::make()->withHeader('x-custom-thing', 'v');
        self::assertSame('v', $r->getHeader('X-Custom-Thing'));
        self::assertSame('v', $r->getHeader('x-custom-thing'));
    }
}
