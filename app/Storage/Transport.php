<?php

declare(strict_types=1);

namespace App\Storage;

/** The one place S3 traffic leaves the process — swapped for a recording fake in tests. */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @param string|null $uploadFile send this file as the body (streamed, never loaded into memory)
     * @param string|null $downloadFile write the response body to this file instead of returning it
     * @return array{status:int,headers:array<string,string>,body:string,error:?string} header names lower-cased; status 0 on a transport failure
     */
    public function send(string $method, string $url, array $headers, ?string $body = null, ?string $uploadFile = null, ?string $downloadFile = null): array;
}
