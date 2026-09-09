<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * HTTP response value object. Built by controllers / the router, emitted once
 * by public/index.php via send().
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var list<array{0:string,1:string,2:array}> */
    private array $cookies = [];

    private string $body = '';
    private ?Closure $streamCallback = null;

    public function __construct(
        string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        $this->body = $body;
        foreach ($headers as $name => $value) {
            $this->headers[$this->normalizeHeader($name)] = $value;
        }
    }

    // ---- Factories -----------------------------------------------------

    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'] + $headers,
        );
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    /**
     * Internal redirect. External URLs are refused unless explicitly allowed
     * (open-redirect guard). Callers should prefer redirect to a named route.
     */
    public static function redirect(string $url, int $status = 302, bool $allowExternal = false): self
    {
        if (!$allowExternal && preg_match('#^(https?:)?//#i', $url)) {
            $url = '/';
        }
        // Strip control chars / header-splitting attempts.
        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? '/';

        return new self('', $status, ['Location' => $url]);
    }

    public static function stream(Closure $callback, int $status = 200, array $headers = []): self
    {
        $response = new self('', $status, $headers);
        $response->streamCallback = $callback;

        return $response;
    }

    // ---- Mutators -----------------------------------------------------

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$this->normalizeHeader($name)] = $value;

        return $this;
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }

        return $this;
    }

    public function withoutHeader(string $name): self
    {
        unset($this->headers[$this->normalizeHeader($name)]);

        return $this;
    }

    public function withCookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = [$name, $value, $options];

        return $this;
    }

    public function withBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    // ---- Accessors --------------------------------------------------

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$this->normalizeHeader($name)] ?? null;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    // ---- Emit ------------------------------------------------------

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            foreach ($this->cookies as [$name, $value, $options]) {
                setcookie($name, $value, $options);
            }
        }

        if ($this->streamCallback !== null) {
            ($this->streamCallback)();

            return;
        }

        echo $this->body;
    }

    private function normalizeHeader(string $name): string
    {
        return ucwords(strtolower(trim($name)), '-');
    }
}
