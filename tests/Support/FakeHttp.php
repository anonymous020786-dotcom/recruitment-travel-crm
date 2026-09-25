<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\HttpClient;

/** Records every outbound HTTP call and answers from a queue — gateway adapters are tested against it, never a live API. */
final class FakeHttp extends HttpClient
{
    /** @var list<array{method:string,url:string,body:?string,headers:array<string,string>}> */
    public array $calls = [];
    /** @var list<array{status:int,json?:array<string,mixed>,body?:string}> */
    private array $queue = [];

    /** @param array{status:int,json?:array<string,mixed>,body?:string} $response */
    public function push(array $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    protected function send(string $method, string $url, ?string $body, array $headers): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
        $r = array_shift($this->queue) ?? ['status' => 200, 'json' => []];
        $text = $r['body'] ?? (isset($r['json']) ? (string) json_encode($r['json']) : '');
        $json = $r['json'] ?? (($d = json_decode($text, true)) !== null && is_array($d) ? $d : null);

        return ['status' => $r['status'], 'body' => $text, 'json' => $json, 'ok' => $r['status'] >= 200 && $r['status'] < 300];
    }

    /** @return array{method:string,url:string,body:?string,headers:array<string,string>} */
    public function last(): array
    {
        return $this->calls[count($this->calls) - 1] ?? throw new \LogicException('no HTTP call was made');
    }

    /** The form/JSON body of the last call as an array. @return array<string,mixed> */
    public function lastBody(): array
    {
        $b = (string) $this->last()['body'];
        $j = json_decode($b, true);
        if (is_array($j)) {
            return $j;
        }
        parse_str($b, $out);

        return $out;
    }
}
