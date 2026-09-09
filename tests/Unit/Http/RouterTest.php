<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        // A fully-wired container so global middleware (RequestId, EnforceHttps,
        // MaintenanceGuard) resolve. env=testing => EnforceHttps is a no-op.
        return new Router(TestApp::make());
    }

    private function request(string $method, string $uri, array $server = []): Request
    {
        return new Request([], [], [], [], $server + [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
        ], '');
    }

    public function test_dispatches_closure_and_normalises_return_types(): void
    {
        $router = $this->router();
        $router->get('/plain', fn () => 'hello');
        $router->get('/arr', fn () => ['ok' => true]);
        $router->get('/resp', fn () => Response::text('x', 201));

        self::assertSame('hello', $router->dispatch($this->request('GET', '/plain'))->getBody());
        self::assertSame('{"ok":true}', $router->dispatch($this->request('GET', '/arr'))->getBody());
        self::assertSame(201, $router->dispatch($this->request('GET', '/resp'))->getStatus());
    }

    public function test_route_parameters_are_injected(): void
    {
        $router = $this->router();
        $router->get('/leads/{lead}', fn (string $lead) => "lead:{$lead}");

        self::assertSame('lead:01H', $router->dispatch($this->request('GET', '/leads/01H'))->getBody());
    }

    public function test_optional_parameter(): void
    {
        $router = $this->router();
        $router->get('/reports/{name?}', fn (Request $r) => 'r:' . ($r->route('name') ?? 'index'));

        self::assertSame('r:index', $router->dispatch($this->request('GET', '/reports'))->getBody());
        self::assertSame('r:leads', $router->dispatch($this->request('GET', '/reports/leads'))->getBody());
    }

    public function test_404_for_unknown_path(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        try {
            $this->router()->dispatch($this->request('GET', '/nope'));
        } catch (HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_405_with_allow_header(): void
    {
        $router = $this->router();
        $router->get('/thing', fn () => 'x');
        $router->post('/thing', fn () => 'y');

        try {
            $router->dispatch($this->request('DELETE', '/thing'));
            self::fail('expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->getStatusCode());
            self::assertStringContainsString('GET', $e->getHeaders()['Allow'] ?? '');
            self::assertStringContainsString('POST', $e->getHeaders()['Allow'] ?? '');
        }
    }

    public function test_groups_apply_prefix_and_name(): void
    {
        $router = $this->router();
        $router->group(['prefix' => 'api', 'name' => 'api.'], function (Router $r): void {
            $r->get('/ping', fn () => 'pong')->name('ping');
        });
        $router->finalizeNames();

        self::assertSame('/api/ping', $router->route('api.ping'));
        self::assertSame('pong', $router->dispatch($this->request('GET', '/api/ping'))->getBody());
    }

    public function test_named_route_url_generation_with_params(): void
    {
        $router = $this->router();
        $router->get('/candidates/{candidate}/documents', fn () => '')->name('candidates.documents');
        $router->finalizeNames();

        self::assertSame('/candidates/01HXYZ/documents', $router->route('candidates.documents', ['candidate' => '01HXYZ']));
    }

    public function test_missing_required_param_throws(): void
    {
        $router = $this->router();
        $router->get('/x/{id}', fn () => '')->name('x');
        $router->finalizeNames();

        $this->expectException(\InvalidArgumentException::class);
        $router->route('x');
    }

    public function test_method_override_is_respected_by_dispatch(): void
    {
        $router = $this->router();
        $router->put('/leads/{lead}', fn (string $lead) => "put:{$lead}");

        $req = new Request([], ['_method' => 'PUT'], [], [], [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/leads/7',
        ], '');

        self::assertSame('put:7', $router->dispatch($req)->getBody());
    }

    public function test_middleware_runs_in_order_and_can_short_circuit(): void
    {
        $router = $this->router();
        $router->get('/guard', fn () => 'through')->middleware([StopMiddleware::class]);

        self::assertSame('stopped', $router->dispatch($this->request('GET', '/guard'))->getBody());
    }
}
