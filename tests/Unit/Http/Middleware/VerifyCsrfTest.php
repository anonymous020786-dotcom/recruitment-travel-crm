<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Exceptions\HttpException;
use App\Http\Middleware\VerifyCsrf;
use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class VerifyCsrfTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(str_repeat('a', 64), []);
        $this->session->token();
    }

    private function request(string $method, array $post = [], array $server = []): Request
    {
        $request = new Request([], $post, [], [], $server + [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => '/leads',
        ], '');
        $request->setAttribute('session', $this->session);

        return $request;
    }

    private function mw(array $cfg = []): VerifyCsrf
    {
        return new VerifyCsrf(TestApp::make([
            'app.url' => 'https://crm.example.com',
            'security.csrf.check_origin' => true,
        ] + $cfg));
    }

    public function test_get_is_exempt(): void
    {
        $res = $this->mw()->handle($this->request('GET'), fn ($r) => Response::text('ok'));
        self::assertSame('ok', $res->getBody());
    }

    public function test_post_without_token_is_419(): void
    {
        $this->expectException(HttpException::class);
        try {
            $this->mw()->handle($this->request('POST'), fn ($r) => Response::text('ok'));
        } catch (HttpException $e) {
            self::assertSame(419, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_post_with_valid_token_passes(): void
    {
        $req = $this->request('POST', ['_token' => $this->session->token()], [
            'HTTP_ORIGIN' => 'https://crm.example.com',
        ]);
        $res = $this->mw()->handle($req, fn ($r) => Response::text('ok'));
        self::assertSame('ok', $res->getBody());
    }

    public function test_token_via_header_passes(): void
    {
        $req = $this->request('POST', [], [
            'HTTP_X_CSRF_TOKEN' => $this->session->token(),
            'HTTP_ORIGIN' => 'https://crm.example.com',
        ]);
        $res = $this->mw()->handle($req, fn ($r) => Response::text('ok'));
        self::assertSame('ok', $res->getBody());
    }

    public function test_wrong_token_is_419(): void
    {
        $this->expectException(HttpException::class);
        $this->mw()->handle(
            $this->request('POST', ['_token' => str_repeat('b', 64)]),
            fn ($r) => Response::text('ok'),
        );
    }

    public function test_cross_origin_is_419_even_with_valid_token(): void
    {
        $req = $this->request('POST', ['_token' => $this->session->token()], [
            'HTTP_ORIGIN' => 'https://evil.example',
        ]);
        $this->expectException(HttpException::class);
        $this->mw()->handle($req, fn ($r) => Response::text('ok'));
    }

    public function test_missing_origin_and_referer_falls_back_to_token_only(): void
    {
        $req = $this->request('POST', ['_token' => $this->session->token()]);
        $res = $this->mw()->handle($req, fn ($r) => Response::text('ok'));
        self::assertSame('ok', $res->getBody());
    }

    public function test_except_path_is_skipped(): void
    {
        $mw = $this->mw(['security.csrf.except' => ['leads']]);
        $res = $mw->handle($this->request('POST'), fn ($r) => Response::text('ok'));
        self::assertSame('ok', $res->getBody());
    }
}
