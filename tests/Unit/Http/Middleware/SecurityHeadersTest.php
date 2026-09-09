<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\SecurityHeaders;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class SecurityHeadersTest extends TestCase
{
    private function request(array $server = []): Request
    {
        return new Request([], [], [], [], $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], '');
    }

    private function dispatch(string $profile, Request $request, string $env = 'production'): Response
    {
        $app = TestApp::make(env: $env);
        $mw = new SecurityHeaders($app, [$profile]);

        return $mw->handle($request, fn (Request $r) => Response::html('<x>'));
    }

    public function test_crm_profile_sets_strict_headers(): void
    {
        $res = $this->dispatch('crm', $this->request(['HTTPS' => 'on']));

        self::assertSame('nosniff', $res->getHeader('X-Content-Type-Options'));
        self::assertSame('DENY', $res->getHeader('X-Frame-Options'));
        self::assertStringContainsString('noindex', (string) $res->getHeader('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $res->getHeader('Cache-Control'));

        $csp = (string) $res->getHeader('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", explode('script-src', $csp)[1] ?? '');
        self::assertMatchesRegularExpression("/script-src[^;]*'nonce-[A-Za-z0-9+\\/=]+'/", $csp);
    }

    public function test_public_profile_is_indexable_and_frames_sameorigin(): void
    {
        $res = $this->dispatch('public', $this->request(['HTTPS' => 'on']));

        self::assertSame('SAMEORIGIN', $res->getHeader('X-Frame-Options'));
        self::assertNull($res->getHeader('X-Robots-Tag'));
        self::assertStringContainsString('public, max-age=', (string) $res->getHeader('Cache-Control'));
    }

    public function test_hsts_only_on_https_production(): void
    {
        self::assertNotNull($this->dispatch('crm', $this->request(['HTTPS' => 'on']), 'production')->getHeader('Strict-Transport-Security'));
        self::assertNull($this->dispatch('crm', $this->request(['HTTPS' => 'off']), 'production')->getHeader('Strict-Transport-Security'));
        self::assertNull($this->dispatch('crm', $this->request(['HTTPS' => 'on']), 'local')->getHeader('Strict-Transport-Security'));
    }

    public function test_nonce_is_exposed_on_request_and_matches_csp(): void
    {
        $app = TestApp::make(env: 'production');
        $request = $this->request(['HTTPS' => 'on']);
        $res = (new SecurityHeaders($app, ['crm']))->handle($request, fn (Request $r) => Response::html('<x>'));

        $nonce = (string) $request->attribute('csp_nonce');
        self::assertNotSame('', $nonce);
        self::assertStringContainsString("'nonce-{$nonce}'", (string) $res->getHeader('Content-Security-Policy'));
    }
}
