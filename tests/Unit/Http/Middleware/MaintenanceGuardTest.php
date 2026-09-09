<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\MaintenanceGuard;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class MaintenanceGuardTest extends TestCase
{
    private string $downFile;

    protected function setUp(): void
    {
        $this->downFile = TEST_ROOT . '/storage/framework/down';
        @unlink($this->downFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->downFile);
    }

    private function request(array $query = [], array $cookies = [], string $ip = '203.0.113.5'): Request
    {
        return new Request($query, [], $cookies, [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => $ip,
        ], '');
    }

    private function down(array $data): void
    {
        @mkdir(dirname($this->downFile), 0777, true);
        file_put_contents($this->downFile, json_encode($data));
    }

    public function test_passes_when_not_down(): void
    {
        $res = (new MaintenanceGuard(TestApp::make()))->handle(
            $this->request(),
            fn (Request $r) => Response::text('ok'),
        );
        self::assertSame('ok', $res->getBody());
    }

    public function test_503_when_down(): void
    {
        $this->down(['secret' => 'abc', 'retry' => 90]);
        $res = (new MaintenanceGuard(TestApp::make()))->handle(
            $this->request(),
            fn (Request $r) => Response::text('ok'),
        );
        self::assertSame(503, $res->getStatus());
        self::assertSame('90', $res->getHeader('Retry-After'));
        self::assertStringContainsString('maintenance', strtolower($res->getBody()));
    }

    public function test_bypass_with_correct_secret_sets_cookie(): void
    {
        $this->down(['secret' => 's3cr3t']);
        $res = (new MaintenanceGuard(TestApp::make()))->handle(
            $this->request(['secret' => 's3cr3t']),
            fn (Request $r) => Response::text('ok'),
        );
        self::assertSame('ok', $res->getBody());
    }

    public function test_wrong_secret_still_blocked(): void
    {
        $this->down(['secret' => 's3cr3t']);
        $res = (new MaintenanceGuard(TestApp::make()))->handle(
            $this->request(['secret' => 'nope']),
            fn (Request $r) => Response::text('ok'),
        );
        self::assertSame(503, $res->getStatus());
    }

    public function test_allowlisted_ip_passes(): void
    {
        $this->down(['secret' => 'x', 'allow' => ['198.51.100.9']]);
        $res = (new MaintenanceGuard(TestApp::make()))->handle(
            $this->request(ip: '198.51.100.9'),
            fn (Request $r) => Response::text('ok'),
        );
        self::assertSame('ok', $res->getBody());
    }
}
