<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\EnforceHttps;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class EnforceHttpsTest extends TestCase
{
    private function request(bool $secure, string $uri = '/leads?page=2'): Request
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'evil.test'];
        if ($secure) {
            $server['HTTPS'] = 'on';
        }

        return new Request([], [], [], [], $server, '');
    }

    public function test_redirects_insecure_requests_in_production_to_canonical_host(): void
    {
        $app = TestApp::make(['app.url' => 'http://crm.example.com'], env: 'production');
        $res = (new EnforceHttps($app))->handle($this->request(false), fn (Request $r) => Response::text('ok'));

        self::assertSame(301, $res->getStatus());
        self::assertSame('https://crm.example.com/leads?page=2', $res->getHeader('Location'));
    }

    public function test_passes_secure_requests_in_production(): void
    {
        $app = TestApp::make(['app.url' => 'https://crm.example.com'], env: 'production');
        $res = (new EnforceHttps($app))->handle($this->request(true), fn (Request $r) => Response::text('ok'));
        self::assertSame('ok', $res->getBody());
    }

    public function test_leaves_non_production_alone(): void
    {
        $app = TestApp::make(['app.url' => 'http://localhost'], env: 'local');
        $res = (new EnforceHttps($app))->handle($this->request(false), fn (Request $r) => Response::text('ok'));
        self::assertSame('ok', $res->getBody());
    }
}
