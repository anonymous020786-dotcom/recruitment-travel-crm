<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RequestId;
use App\Http\Request;
use App\Http\Response;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;

final class RequestIdTest extends TestCase
{
    private function request(array $server = []): Request
    {
        return new Request([], [], [], [], $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], '');
    }

    private function mw(): RequestId
    {
        return new RequestId(new Logger(sys_get_temp_dir() . '/crm_reqid_logs'));
    }

    public function test_generates_id_when_absent(): void
    {
        $request = $this->request();
        $res = $this->mw()->handle($request, fn (Request $r) => Response::text('ok'));

        $id = (string) $request->attribute('request_id');
        self::assertSame(26, strlen($id));
        self::assertSame($id, $res->getHeader('X-Request-Id'));
    }

    public function test_accepts_well_formed_incoming_id(): void
    {
        $request = $this->request(['HTTP_X_REQUEST_ID' => 'trace-abc_123']);
        $this->mw()->handle($request, fn (Request $r) => Response::text('ok'));
        self::assertSame('trace-abc_123', $request->attribute('request_id'));
    }

    public function test_rejects_malformed_incoming_id(): void
    {
        $request = $this->request(['HTTP_X_REQUEST_ID' => 'bad id with spaces and <tags>']);
        $this->mw()->handle($request, fn (Request $r) => Response::text('ok'));
        self::assertNotSame('bad id with spaces and <tags>', $request->attribute('request_id'));
        self::assertSame(26, strlen((string) $request->attribute('request_id')));
    }
}
