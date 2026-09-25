<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * AWS Signature Version 4 for S3 and S3-compatible services (Cloudflare R2, MinIO, Backblaze B2…), implemented from the
 * published algorithm with no SDK. Two forms: signed request headers (`sign`) and a pre-signed URL (`presign`).
 *
 * The canonical URI is the key percent-encoded once per RFC 3986, slashes kept (S3 does not double-encode). Header names
 * are lower-cased and their values trimmed with inner whitespace collapsed. Tested against the worked examples in the AWS
 * documentation (GET object with Range, PUT object with a storage class, and a pre-signed GET).
 */
final class SigV4
{
    public const UNSIGNED = 'UNSIGNED-PAYLOAD';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    /** @param string $path the raw, unencoded object path beginning with "/" */
    public static function canonicalUri(string $path): string
    {
        return implode('/', array_map(static fn (string $seg): string => rawurlencode($seg), explode('/', $path)));
    }

    /** @param array<string,string|int> $query */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[rawurlencode((string) $k)] = rawurlencode((string) $v);
        }
        ksort($pairs, SORT_STRING);
        $out = [];
        foreach ($pairs as $k => $v) {
            $out[] = $k . '=' . $v;
        }

        return implode('&', $out);
    }

    /**
     * Sign a request. `$headers` must already contain `host`; `x-amz-date` and `x-amz-content-sha256` are added if absent.
     *
     * @param array<string,string|int> $query
     * @param array<string,string> $headers
     * @param array{key:string,secret:string} $credentials
     * @return array{authorization:string,signature:string,signed_headers:string,headers:array<string,string>,canonical_request:string}
     */
    public static function sign(string $method, string $path, array $query, array $headers, string $payloadHash, array $credentials, string $region, string $service, int $timestamp): array
    {
        $amzDate = gmdate('Ymd\THis\Z', $timestamp);
        $day = gmdate('Ymd', $timestamp);
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['x-amz-date'] ??= $amzDate;
        $headers['x-amz-content-sha256'] ??= $payloadHash;

        [$canonicalHeaders, $signedHeaders] = self::headers($headers);
        $canonicalRequest = implode("\n", [strtoupper($method), self::canonicalUri($path), self::canonicalQuery($query), $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$day}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [self::ALGORITHM, $headers['x-amz-date'], $scope, hash('sha256', $canonicalRequest)]);
        $signature = hash_hmac('sha256', $stringToSign, self::signingKey($credentials['secret'], $day, $region, $service));

        return [
            'authorization' => self::ALGORITHM . " Credential={$credentials['key']}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
            'signature' => $signature,
            'signed_headers' => $signedHeaders,
            'headers' => $headers,
            'canonical_request' => $canonicalRequest,
        ];
    }

    /**
     * A pre-signed URL query for a GET/HEAD/PUT of one object, valid for `$expires` seconds (1–604800).
     *
     * @param array<string,string|int> $extraQuery e.g. response-content-disposition
     * @param array{key:string,secret:string} $credentials
     * @return array{query:array<string,string>,signature:string}
     */
    public static function presign(string $method, string $host, string $path, array $extraQuery, array $credentials, string $region, string $service, int $timestamp, int $expires): array
    {
        $amzDate = gmdate('Ymd\THis\Z', $timestamp);
        $day = gmdate('Ymd', $timestamp);
        $scope = "{$day}/{$region}/{$service}/aws4_request";
        $query = $extraQuery + [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => "{$credentials['key']}/{$scope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(1, min($expires, 604800)),
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalRequest = implode("\n", [strtoupper($method), self::canonicalUri($path), self::canonicalQuery($query), "host:{$host}\n", 'host', self::UNSIGNED]);
        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);
        $signature = hash_hmac('sha256', $stringToSign, self::signingKey($credentials['secret'], $day, $region, $service));

        return ['query' => array_map('strval', $query) + ['X-Amz-Signature' => $signature], 'signature' => $signature];
    }

    public static function signingKey(string $secret, string $day, string $region, string $service): string
    {
        $k = hash_hmac('sha256', $day, 'AWS4' . $secret, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);

        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    /**
     * @param array<string,string|int> $headers lower-cased names
     * @return array{0:string,1:string} [canonical headers block (each line ends with \n), signed-headers list]
     */
    private static function headers(array $headers): array
    {
        ksort($headers, SORT_STRING);
        $block = '';
        foreach ($headers as $name => $value) {
            $block .= $name . ':' . trim((string) preg_replace('/\s+/', ' ', (string) $value)) . "\n";
        }

        return [$block, implode(';', array_keys($headers))];
    }
}
