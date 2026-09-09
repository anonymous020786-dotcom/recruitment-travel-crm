<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Session\ArraySessionStore;
use App\Session\Session;
use App\Session\SessionStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/**
 * End-to-end through the real Kernel middleware stack (global + web.crm group)
 * with an in-memory session store.
 */
final class HttpKernelTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $app = TestApp::make([
            'app.url' => 'http://localhost',
            'session.secure' => false,
            'session.cookie' => 'crm_session',
        ]);
        $app->instance(SessionStore::class, new ArraySessionStore());

        $this->router = new Router($app);
        $this->router->group(['middleware' => ['web.crm'], 'name' => 'crm.'], function (Router $r): void {
            $r->get('/things', fn () => Response::json(['ok' => true]))->name('things.index');
            $r->post('/things', fn (Request $req) => Response::json(['created' => $req->input('name')]))->name('things.store');
        });
        $this->router->finalizeNames();
    }

    private function request(string $method, string $uri, array $post = [], array $cookies = [], array $server = []): Request
    {
        return new Request([], $post, $cookies, [], $server + [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
        ], '');
    }

    public function test_get_issues_session_and_security_headers(): void
    {
        $res = $this->router->dispatch($this->request('GET', '/things'));

        self::assertSame(200, $res->getStatus());
        self::assertNotNull($res->getHeader('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $res->getHeader('Cache-Control'));
        self::assertNotNull($res->getHeader('X-Request-Id'));
    }

    public function test_post_without_csrf_token_is_419(): void
    {
        try {
            $this->router->dispatch($this->request('POST', '/things', ['name' => 'Widget']));
            self::fail('expected 419');
        } catch (\App\Exceptions\HttpException $e) {
            self::assertSame(419, $e->getStatusCode());
        }
    }

    public function test_post_with_valid_csrf_token_succeeds(): void
    {
        // 1. GET to establish a session + learn the token.
        $getRequest = $this->request('GET', '/things');
        $this->router->dispatch($getRequest);
        /** @var Session $session */
        $session = $getRequest->attribute('session');
        $token = $session->token();
        $sid = $session->id();

        // 2. POST back with the cookie + token + same-origin.
        $postRequest = $this->request('POST', '/things', ['name' => 'Widget', '_token' => $token], [
            'crm_session' => $sid,
        ], ['HTTP_ORIGIN' => 'http://localhost']);

        $res = $this->router->dispatch($postRequest);
        self::assertSame(200, $res->getStatus());
        self::assertStringContainsString('Widget', $res->getBody());
    }

    public function test_post_with_token_but_foreign_origin_is_419(): void
    {
        $getRequest = $this->request('GET', '/things');
        $this->router->dispatch($getRequest);
        $session = $getRequest->attribute('session');

        $postRequest = $this->request('POST', '/things', ['name' => 'X', '_token' => $session->token()], [
            'crm_session' => $session->id(),
        ], ['HTTP_ORIGIN' => 'http://attacker.example']);

        $this->expectException(\App\Exceptions\HttpException::class);
        $this->router->dispatch($postRequest);
    }
}
