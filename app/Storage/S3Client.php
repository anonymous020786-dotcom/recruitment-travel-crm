<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * A small, dependency-free client for the S3 API — Amazon S3 and Cloudflare R2 (or any S3-compatible endpoint).
 *
 * Everything is one object per key inside one bucket, under an optional key prefix. Uploads stream the file and sign its real
 * SHA-256, so the service rejects anything corrupted in transit. Downloads can stream to a file. Nothing here throws on a bad
 * HTTP status: every method returns the status (and the service's error code when it sends one) so callers decide.
 *
 * Config: endpoint host (no scheme), region, bucket, key/secret, `path_style` (bucket in the path rather than the host name —
 * always for R2 and for bucket names containing dots), `prefix`, `storage_class`.
 */
final class S3Client
{
    /** @var callable():int */
    private $clock;

    /**
     * @param array{host:string,region:string,bucket:string,key:string,secret:string,path_style?:bool,prefix?:string,storage_class?:string,scheme?:string} $config
     * @param (callable():int)|null $clock unix time source (injectable for tests)
     */
    public function __construct(private readonly Transport $transport, private readonly array $config, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    // ---- objects -----------------------------------------------------------------------------------------------------

    /**
     * Upload a file. `$storageClass` overrides the configured default for this object.
     *
     * @param array<string,string> $metadata stored as x-amz-meta-* (lower-cased names)
     * @return array{ok:bool,status:int,etag:?string,error:?string}
     */
    public function put(string $key, string $file, string $contentType, array $metadata = [], ?string $storageClass = null, ?string $cacheControl = null): array
    {
        $hash = @hash_file('sha256', $file);
        if ($hash === false) {
            return ['ok' => false, 'status' => 0, 'etag' => null, 'error' => 'the file cannot be read'];
        }
        $headers = ['content-type' => $contentType, 'content-length' => (string) filesize($file)];
        $class = $storageClass ?? ($this->config['storage_class'] ?? '');
        if ($class !== '' && $class !== 'STANDARD') {
            $headers['x-amz-storage-class'] = $class;
        }
        if ($cacheControl !== null) {
            $headers['cache-control'] = $cacheControl;
        }
        foreach ($metadata as $name => $value) {
            $headers['x-amz-meta-' . strtolower((string) $name)] = (string) $value;
        }
        $r = $this->request('PUT', $key, [], $headers, $hash, uploadFile: $file);

        return ['ok' => $r['status'] === 200, 'status' => $r['status'], 'etag' => isset($r['headers']['etag']) ? trim($r['headers']['etag'], '"') : null, 'error' => $r['error']];
    }

    /**
     * Fetch an object; with `$toFile` the body is streamed there instead of returned.
     *
     * @return array{ok:bool,status:int,body:string,headers:array<string,string>,error:?string}
     */
    public function get(string $key, ?string $toFile = null): array
    {
        $r = $this->request('GET', $key, [], [], hash('sha256', ''), downloadFile: $toFile);

        return ['ok' => $r['status'] === 200, 'status' => $r['status'], 'body' => $r['body'], 'headers' => $r['headers'], 'error' => $r['error']];
    }

    /** @return array{size:int,etag:?string,type:?string,class:?string}|null null when the object does not exist (or the call failed) */
    public function head(string $key): ?array
    {
        $r = $this->request('HEAD', $key, [], [], hash('sha256', ''));
        if ($r['status'] !== 200) {
            return null;
        }
        $h = $r['headers'];

        return ['size' => (int) ($h['content-length'] ?? 0), 'etag' => isset($h['etag']) ? trim($h['etag'], '"') : null, 'type' => $h['content-type'] ?? null, 'class' => $h['x-amz-storage-class'] ?? null];
    }

    /** Delete an object. S3 answers 204 whether or not it existed. */
    public function delete(string $key): bool
    {
        return in_array($this->request('DELETE', $key, [], [], hash('sha256', ''))['status'], [200, 204], true);
    }

    /**
     * A time-limited URL that lets the holder download one object directly from the storage service — no server bandwidth used.
     * The filename and type are forced by the URL so a stored file can never be rendered as a web page.
     */
    public function presignGet(string $key, int $ttlSeconds, ?string $downloadName = null, ?string $contentType = null, bool $inline = false): string
    {
        [$host, $path] = $this->target($key);
        $extra = [];
        if ($downloadName !== null) {
            $safe = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $downloadName) ?? 'file';
            $extra['response-content-disposition'] = ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"';
        }
        if ($contentType !== null) {
            $extra['response-content-type'] = $contentType;
        }
        $signed = SigV4::presign('GET', $host, $path, $extra, ['key' => $this->config['key'], 'secret' => $this->config['secret']], $this->config['region'], 's3', ($this->clock)(), $ttlSeconds);

        return $this->scheme() . '://' . $host . SigV4::canonicalUri($path) . '?' . SigV4::canonicalQuery($signed['query']);
    }

    /**
     * One page of a listing under a prefix (relative to the client's own prefix).
     *
     * @return array{keys:list<array{key:string,size:int,modified:string}>,truncated:bool,next:?string,status:int}
     */
    public function list(string $prefix = '', int $max = 1000, ?string $continuation = null): array
    {
        $query = ['list-type' => '2', 'max-keys' => (string) max(1, min($max, 1000)), 'prefix' => $this->fullKey($prefix)];
        if ($continuation !== null) {
            $query['continuation-token'] = $continuation;
        }
        $r = $this->request('GET', null, $query, [], hash('sha256', ''));
        $keys = [];
        $truncated = false;
        $next = null;
        if ($r['status'] === 200 && $r['body'] !== '') {
            $xml = @simplexml_load_string($r['body']);
            if ($xml !== false) {
                $strip = strlen($this->fullKey(''));
                foreach ($xml->Contents ?? [] as $c) {
                    $keys[] = ['key' => substr((string) $c->Key, $strip), 'size' => (int) $c->Size, 'modified' => (string) $c->LastModified];
                }
                $truncated = strtolower((string) $xml->IsTruncated) === 'true';
                $next = $truncated ? (string) $xml->NextContinuationToken : null;
            }
        }

        return ['keys' => $keys, 'truncated' => $truncated, 'next' => $next, 'status' => $r['status']];
    }

    // ---- bucket -----------------------------------------------------------------------------------------------------------

    /** Whether the credentials can reach the bucket (HEAD bucket) — the "test connection" check. @return array{ok:bool,status:int,message:string} */
    public function checkBucket(): array
    {
        $r = $this->request('HEAD', null, [], [], hash('sha256', ''));
        $message = match (true) {
            $r['status'] === 200 => 'Connected: the bucket is reachable with these credentials.',
            $r['status'] === 403 => 'The service answered but refused these credentials (403). Check the key, secret and bucket permissions.',
            $r['status'] === 404 => 'The bucket was not found (404). Check the bucket name and region/account.',
            $r['status'] === 0 => 'Could not reach the service: ' . ($r['error'] ?? 'no response') . '.',
            default => 'Unexpected answer from the service (HTTP ' . $r['status'] . ').',
        };

        return ['ok' => $r['status'] === 200, 'status' => $r['status'], 'message' => $message];
    }

    /** Apply a lifecycle configuration (see Lifecycle::xml). @return array{ok:bool,status:int,error:?string} */
    public function putLifecycle(string $xml): array
    {
        $r = $this->request('PUT', null, ['lifecycle' => ''], ['content-type' => 'application/xml', 'content-md5' => base64_encode(md5($xml, true))], hash('sha256', $xml), body: $xml);

        return ['ok' => in_array($r['status'], [200, 204], true), 'status' => $r['status'], 'error' => $r['status'] >= 300 ? self::errorCode($r['body']) : $r['error']];
    }

    /** The S3 error code (`AccessDenied`, `NoSuchKey`…) in an error body, if any. */
    public static function errorCode(string $body): ?string
    {
        return preg_match('~<Code>([A-Za-z0-9]+)</Code>~', $body, $m) === 1 ? $m[1] : null;
    }

    public function fullKey(string $key): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');

        return ($prefix !== '' ? $prefix . '/' : '') . ltrim($key, '/');
    }

    // ---- internals ------------------------------------------------------------------------------------------------------------

    private function scheme(): string
    {
        return $this->config['scheme'] ?? 'https';
    }

    /** @return array{0:string,1:string} host and raw (unencoded) path for an object key, or for the bucket itself when null */
    private function target(?string $key): array
    {
        $bucket = $this->config['bucket'];
        $host = $this->config['host'];
        $pathStyle = (bool) ($this->config['path_style'] ?? false) || str_contains($bucket, '.');
        $objectPath = $key === null ? '' : '/' . $this->fullKey($key);
        if ($pathStyle) {
            return [$host, '/' . $bucket . $objectPath];
        }

        return [$bucket . '.' . $host, $key === null ? '/' : $objectPath];
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string,error:?string}
     */
    private function request(string $method, ?string $key, array $query, array $headers, string $payloadHash, ?string $body = null, ?string $uploadFile = null, ?string $downloadFile = null): array
    {
        [$host, $path] = $this->target($key);
        $signed = SigV4::sign($method, $path, $query, $headers + ['host' => $host], $payloadHash, ['key' => $this->config['key'], 'secret' => $this->config['secret']], $this->config['region'], 's3', ($this->clock)());
        $send = $signed['headers'] + ['authorization' => $signed['authorization']];
        unset($send['host']);   // the transport sets Host from the URL

        $url = $this->scheme() . '://' . $host . SigV4::canonicalUri($path);
        if ($query !== []) {
            $url .= '?' . implode('&', array_map(static fn (string $k, string $v): string => rawurlencode($k) . ($v === '' ? '=' : '=' . rawurlencode($v)), array_keys($query), $query));
        }

        return $this->transport->send($method, $url, $send, $body, $uploadFile, $downloadFile);
    }
}
