<?php

declare(strict_types=1);

namespace App\Storage;

/** cURL transport: TLS verified, no redirects followed, files streamed in and out, bounded timeouts. */
final class CurlTransport implements Transport
{
    public function __construct(private readonly int $timeoutSeconds = 60, private readonly int $connectSeconds = 10)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body = null, ?string $uploadFile = null, ?string $downloadFile = null): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'the cURL extension is not available'];
        }
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        $lines[] = 'Expect:';   // no 100-continue round trip for uploads

        $responseHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_USERAGENT => 'RecruitmentTravelCRM-Storage/1.0',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        $upload = null;
        $download = null;
        if ($uploadFile !== null) {
            $upload = fopen($uploadFile, 'rb');
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $upload;
            $options[CURLOPT_INFILESIZE] = (int) filesize($uploadFile);
        } elseif ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($downloadFile !== null) {
            $download = fopen($downloadFile, 'wb');
            $options[CURLOPT_RETURNTRANSFER] = false;
            $options[CURLOPT_FILE] = $download;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        foreach ([$upload, $download] as $h) {
            if (is_resource($h)) {
                fclose($h);
            }
        }
        if ($response === false) {
            return ['status' => 0, 'headers' => $responseHeaders, 'body' => '', 'error' => $error !== '' ? $error : 'request failed'];
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => is_string($response) ? $response : '', 'error' => null];
    }
}
