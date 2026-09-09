<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Immutable-ish view of the incoming HTTP request. Built once from PHP
 * superglobals in public/index.php; superglobals are not touched anywhere else.
 */
final class Request
{
    /** @var array<string,string> route parameters, filled by the Router */
    private array $routeParams = [];

    /** @var array<string,mixed> per-request scratch space set by middleware (csp nonce, request id, auth user, ...) */
    private array $attributes = [];

    private ?array $jsonCache = null;

    /**
     * @param array<string,mixed> $query   $_GET
     * @param array<string,mixed> $post    $_POST
     * @param array<string,mixed> $cookies $_COOKIE
     * @param array<string,mixed> $files   $_FILES (normalised)
     * @param array<string,string> $server $_SERVER
     */
    public function __construct(
        private readonly array $query,
        private readonly array $post,
        private readonly array $cookies,
        private readonly array $files,
        private readonly array $server,
        private readonly string $rawBody,
        private readonly array $trustedProxies = [],
    ) {
    }

    public static function capture(array $trustedProxies = []): self
    {
        return new self(
            query: $_GET,
            post: $_POST,
            cookies: $_COOKIE,
            files: self::normalizeFiles($_FILES),
            server: $_SERVER,
            rawBody: file_get_contents('php://input') ?: '',
            trustedProxies: $trustedProxies,
        );
    }

    // ---- Method / path ---------------------------------------------------

    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST') {
            $override = strtoupper((string) ($this->post['_method'] ?? $this->header('X-HTTP-Method-Override') ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public function realMethod(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isReading(): bool
    {
        return in_array($this->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);

        return '/' . trim($path, '/');
    }

    public function fullUrl(): string
    {
        return $this->scheme() . '://' . $this->host() . ($this->server['REQUEST_URI'] ?? '/');
    }

    public function scheme(): string
    {
        if ($this->isFromTrustedProxy() && ($proto = $this->header('X-Forwarded-Proto')) !== null) {
            return strtolower(explode(',', $proto)[0]) === 'https' ? 'https' : 'http';
        }

        $https = $this->server['HTTPS'] ?? '';

        return ($https !== '' && strtolower((string) $https) !== 'off') ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    public function host(): string
    {
        // Never trust Host blindly for link generation — that uses APP_URL.
        return (string) ($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost');
    }

    // ---- Input ---------------------------------------------------------

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if ($this->isJson()) {
            return $this->json()[$key] ?? $default;
        }

        return $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->isJson() ? $this->json() : $this->post);
    }

    /** @param list<string> $keys @return array<string,mixed> */
    public function only(array $keys): array
    {
        $all = $this->all();

        return array_intersect_key($all, array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function filled(string $key): bool
    {
        $v = $this->input($key);

        return $v !== null && $v !== '' && $v !== [];
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $v = $this->input($key);
        if ($v === null) {
            return $default;
        }

        return filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $v = $this->input($key);

        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        $decoded = json_decode($this->rawBody, true);

        return $this->jsonCache = is_array($decoded) ? $decoded : [];
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type') ?? ''), 'json');
    }

    public function wantsJson(): bool
    {
        $accept = strtolower($this->header('Accept') ?? '');

        return ($this->attributes['force_json'] ?? false) === true
            || $this->isJson()
            || str_contains($accept, 'application/json')
            || str_contains($accept, '+json')
            || $this->isXmlHttpRequest();
    }

    public function isXmlHttpRequest(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    // ---- Headers / cookies / files ------------------------------------

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($this->server[$key])) {
            return $this->server[$key];
        }
        if ($name === 'Content-Type' && isset($this->server['CONTENT_TYPE'])) {
            return $this->server['CONTENT_TYPE'];
        }
        if ($name === 'Content-Length' && isset($this->server['CONTENT_LENGTH'])) {
            return $this->server['CONTENT_LENGTH'];
        }

        return $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization') ?? '';

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        $f = $this->file($key);

        return $f !== null && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    // ---- Client identity --------------------------------------------

    public function ip(): string
    {
        $remote = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($this->isFromTrustedProxy()) {
            $forwarded = $this->header('X-Forwarded-For');
            if ($forwarded !== null) {
                $parts = array_map('trim', explode(',', $forwarded));
                $candidate = $parts[0] ?? $remote;
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    public function ipBinary(): string
    {
        $packed = @inet_pton($this->ip());

        return $packed === false ? "\0\0\0\0" : $packed;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    private function isFromTrustedProxy(): bool
    {
        if ($this->trustedProxies === []) {
            return false;
        }
        $remote = $this->server['REMOTE_ADDR'] ?? '';

        return in_array($remote, $this->trustedProxies, true) || in_array('*', $this->trustedProxies, true);
    }

    // ---- Route params (Router fills these) --------------------------

    /** @param array<string,string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /** @return array<string,string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    // ---- Attributes (middleware scratch space) --------------------

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    // ---- File normalisation ----------------------------------------

    private static function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $file) {
            if (!is_array($file) || !isset($file['name'])) {
                continue;
            }
            if (is_array($file['name'])) {
                foreach ($file['name'] as $i => $_) {
                    $normalized[$key][$i] = [
                        'name'     => $file['name'][$i],
                        'type'     => $file['type'][$i] ?? null,
                        'tmp_name' => $file['tmp_name'][$i] ?? null,
                        'error'    => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $file['size'][$i] ?? 0,
                    ];
                }
            } else {
                $normalized[$key] = $file;
            }
        }

        return $normalized;
    }
}
