<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal outbound HTTP client (cURL). Used for server-to-server calls only —
 * Turnstile verification now, WhatsApp Business API / payment gateways later.
 * Injectable so tests can substitute a fake.
 */
class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 8,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * @param array<string,scalar> $form  application/x-www-form-urlencoded body
     * @param array<string,string> $headers
     * @return array{status:int,body:string,json:array<string,mixed>|null,ok:bool}
     */
    public function postForm(string $url, array $form, array $headers = []): array
    {
        return $this->send('POST', $url, http_build_query($form), $headers + [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
    }

    /** @param array<string,mixed> $json */
    public function postJson(string $url, array $json, array $headers = []): array
    {
        return $this->send('POST', $url, json_encode($json, JSON_UNESCAPED_SLASHES) ?: '{}', $headers + [
            'Content-Type' => 'application/json',
        ]);
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->send('GET', $url, null, $headers);
    }

    /** @return array{status:int,body:string,json:array<string,mixed>|null,ok:bool} */
    protected function send(string $method, string $url, ?string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            $this->logger?->error('HttpClient: cURL extension not available');

            return ['status' => 0, 'body' => '', 'json' => null, 'ok' => false];
        }

        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_USERAGENT      => 'RecruitmentTravelCRM/1.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger?->warning('HttpClient request failed: {url} ({error})', ['url' => $url, 'error' => $error]);

            return ['status' => 0, 'body' => '', 'json' => null, 'ok' => false];
        }

        $decoded = json_decode((string) $response, true);

        return [
            'status' => $status,
            'body'   => (string) $response,
            'json'   => is_array($decoded) ? $decoded : null,
            'ok'     => $status >= 200 && $status < 300,
        ];
    }
}
