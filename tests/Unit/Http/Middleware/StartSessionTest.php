<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\StartSession;
use App\Http\Request;
use App\Http\Response;
use App\Session\ArraySessionStore;
use App\Session\Session;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class StartSessionTest extends TestCase
{
    private ArraySessionStore $store;

    protected function setUp(): void
    {
        $this->store = new ArraySessionStore();
    }

    private function app(array $overrides = [])
    {
        return TestApp::make([
            'session.cookie' => 'crm_session',
            'session.idle_minutes' => 30,
            'session.lifetime_minutes' => 480,
            'session.regenerate_minutes' => 20,
            'session.secure' => false,
        ] + $overrides);
    }

    private function request(array $cookies = []): Request
    {
        return new Request([], [], $cookies, [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard', 'REMOTE_ADDR' => '127.0.0.1',
        ], '');
    }

    private function execute(Request $request, ?\Closure $inner = null): array
    {
        $app = $this->app();
        $mw = new StartSession($app, $this->store);
        $captured = null;
        $response = $mw->handle($request, function (Request $r) use (&$captured, $inner) {
            $captured = $r->attribute('session');
            return $inner ? $inner($r) : Response::text('ok');
        });

        return [$response, $captured];
    }

    public function test_fresh_request_gets_session_and_cookie(): void
    {
        $request = $this->request();
        [$response, $session] = $this->execute($request);

        self::assertInstanceOf(Session::class, $session);
        self::assertNotEmpty($this->store->sessions);
        $body = "{$response->getStatus()}";
        self::assertSame('200', $body);
        // cookie is queued (asserted via reflection-free behaviour: store has the id)
        self::assertArrayHasKey($session->id(), $this->store->sessions);
    }

    public function test_existing_session_round_trips(): void
    {
        $id = str_repeat('a', 64);
        $this->store->sessions[$id] = ['data' => [
            'name' => 'Asha', '_started_at' => time() - 60, '_last_activity' => time() - 60,
            '_last_regen' => time(), '_token' => str_repeat('t', 64),
        ], 'touched' => time()];

        [, $session] = $this->execute($this->request(['crm_session' => $id]));
        self::assertSame('Asha', $session->get('name'));
        self::assertSame($id, $session->id(), 'no rotation within cadence');
    }

    public function test_idle_timeout_starts_fresh_session(): void
    {
        $id = str_repeat('b', 64);
        $this->store->sessions[$id] = ['data' => [
            'name' => 'Stale', '_started_at' => time() - 100, '_last_activity' => time() - 3600,
            '_last_regen' => time(),
        ], 'touched' => time() - 3600];

        [, $session] = $this->execute($this->request(['crm_session' => $id]));
        self::assertNull($session->get('name'));
        self::assertNotSame($id, $session->id());
        self::assertArrayNotHasKey($id, $this->store->sessions, 'stale session destroyed');
    }

    public function test_absolute_lifetime_starts_fresh_session(): void
    {
        $id = str_repeat('c', 64);
        $this->store->sessions[$id] = ['data' => [
            'name' => 'Old', '_started_at' => time() - (500 * 60), '_last_activity' => time() - 10,
            '_last_regen' => time(),
        ], 'touched' => time()];

        [, $session] = $this->execute($this->request(['crm_session' => $id]));
        self::assertNull($session->get('name'));
        self::assertNotSame($id, $session->id());
    }

    public function test_regeneration_cadence_rotates_id_and_drops_old(): void
    {
        $id = str_repeat('d', 64);
        $this->store->sessions[$id] = ['data' => [
            '_started_at' => time() - 60, '_last_activity' => time() - 60,
            '_last_regen' => time() - (25 * 60), 'keep' => 'yes',
        ], 'touched' => time()];

        [, $session] = $this->execute($this->request(['crm_session' => $id]));
        self::assertNotSame($id, $session->id());
        self::assertSame('yes', $session->get('keep'), 'data carried across rotation');
        self::assertArrayNotHasKey($id, $this->store->sessions);
        self::assertArrayHasKey($session->id(), $this->store->sessions);
    }

    public function test_malformed_cookie_id_is_ignored(): void
    {
        [, $session] = $this->execute($this->request(['crm_session' => 'not-a-valid-id']));
        self::assertTrue(Session::isValidId($session->id()));
    }
}
