<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Auth\Auth;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class RequireRecentAuthTest extends TestCase
{
    private function auth(bool $recent): Auth
    {
        return new class($recent) extends Auth {
            public function __construct(private bool $recent)
            {
            }

            public function authenticatedRecently(int $withinMinutes = 30): bool
            {
                return $this->recent;
            }
        };
    }

    private function request(string $method, array $server = []): Request
    {
        $request = new Request([], [], [], [], $server + [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => '/account/security',
        ], '');
        $request->setAttribute('session', new Session(str_repeat('a', 64), []));

        return $request;
    }

    private function mw(bool $recent, array $cfg = []): RequireRecentAuth
    {
        return new RequireRecentAuth(TestApp::make($cfg), $this->auth($recent));
    }

    public function test_passes_when_recently_authenticated(): void
    {
        $res = $this->mw(true)->handle($this->request('GET'), fn ($r) => Response::text('ok'));
        self::assertSame('ok', $res->getBody());
    }

    public function test_get_redirects_to_confirm_and_stashes_target(): void
    {
        $request = $this->request('GET');
        $res = $this->mw(false)->handle($request, fn ($r) => Response::text('ok'));

        self::assertSame(302, $res->getStatus());
        self::assertSame('/confirm-password', $res->getHeader('Location'));
        self::assertSame('/account/security', $request->attribute('session')->get('_confirm_intended'));
    }

    public function test_post_stashes_referer_path(): void
    {
        $request = $this->request('POST', ['HTTP_REFERER' => 'https://crm.example.com/account/passkeys']);
        $this->mw(false)->handle($request, fn ($r) => Response::text('ok'));

        self::assertSame('/account/passkeys', $request->attribute('session')->get('_confirm_intended'));
    }

    public function test_json_gets_403_with_confirm_flag(): void
    {
        $request = $this->request('POST', ['HTTP_ACCEPT' => 'application/json']);
        $res = $this->mw(false)->handle($request, fn ($r) => Response::text('ok'));

        self::assertSame(403, $res->getStatus());
        $body = json_decode($res->getBody(), true);
        self::assertTrue($body['confirm_required']);
        self::assertSame('/confirm-password', $body['confirm_url']);
    }

    public function test_minutes_argument_overrides_config(): void
    {
        // recent=false regardless; just assert the arg is accepted and still redirects.
        $mw = new RequireRecentAuth(TestApp::make(), $this->auth(false), ['5']);
        $res = $mw->handle($this->request('GET'), fn ($r) => Response::text('ok'));
        self::assertSame(302, $res->getStatus());
    }
}
