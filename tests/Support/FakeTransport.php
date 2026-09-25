<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Storage\Transport;

/** Records every request and answers from a queue (or a default). Lets storage/gateway code be tested without a network. */
final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string,upload:?string,download:?string}> */
    public array $requests = [];
    /** @var list<array{status:int,headers?:array<string,string>,body?:string,error?:?string,file?:string}> */
    private array $queue = [];

    /** @param array{status:int,headers?:array<string,string>,body?:string,error?:?string,file?:string} $response */
    public function push(array $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body = null, ?string $uploadFile = null, ?string $downloadFile = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'upload' => $uploadFile, 'download' => $downloadFile];
        $r = array_shift($this->queue) ?? ['status' => 200];
        if ($downloadFile !== null && isset($r['file'])) {
            copy($r['file'], $downloadFile);
        }

        return ['status' => $r['status'], 'headers' => array_change_key_case($r['headers'] ?? [], CASE_LOWER), 'body' => $r['body'] ?? '', 'error' => $r['error'] ?? null];
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:?string,upload:?string,download:?string} */
    public function last(): array
    {
        return $this->requests[count($this->requests) - 1] ?? throw new \LogicException('no request was made');
    }
}
